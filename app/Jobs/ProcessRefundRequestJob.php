<?php

namespace App\Jobs;

use App\Models\RefundRequest;
use App\Services\PaymentRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRefundRequestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $refundRequestId)
    {
    }

    public function handle(PaymentRouter $router): void
    {
        $refund = RefundRequest::query()->with('transaction.paymentMethod')->find($this->refundRequestId);

        if (! $refund) {
            return;
        }

        if (! in_array($refund->status, ['approved', 'failed'], true)) {
            return;
        }

        $transaction = $refund->transaction;
        $method = $transaction?->paymentMethod;

        if (! $transaction || ! $method || ! $transaction->provider_reference) {
            $refund->update([
                'status' => 'manual_review',
                'processed_at' => now(),
                'failure_reason' => 'Refund cannot be processed automatically because transaction/provider reference is missing.',
            ]);
            $this->fireCallback($refund->fresh());

            return;
        }

        try {
            $refund->update([
                'status' => 'processing',
                'processed_at' => now(),
                'provider_key' => $method->provider_key,
            ]);

            $driver = $router->driverFor($method);
            $result = $driver->refund($transaction->provider_reference, (float) $refund->amount);
            $ok = (bool) ($result['ok'] ?? false);
            $status = $result['status'] ?? null;

            if ($ok && in_array($status, ['completed', 'confirmed', 'success'], true)) {
                $refund->update([
                    'status' => 'completed',
                    'provider_response' => $result,
                    'provider_reference' => $result['providerReference'] ?? $refund->provider_reference,
                    'completed_at' => now(),
                    'failure_reason' => null,
                ]);
                $this->fireCallback($refund->fresh());

                return;
            }

            if ($ok) {
                $refund->update([
                    'status' => 'processing',
                    'provider_response' => $result,
                    'provider_reference' => $result['providerReference'] ?? $refund->provider_reference,
                    'failure_reason' => null,
                ]);

                return;
            }

            $message = (string) ($result['message'] ?? 'Provider refund was not accepted.');
            $manual = str_contains(strtolower($message), 'not supported') || str_contains(strtolower($message), 'manual');

            $refund->update([
                'status' => $manual ? 'manual_review' : 'failed',
                'provider_response' => $result,
                'failure_reason' => $message,
                'failed_at' => $manual ? null : now(),
            ]);
            $this->fireCallback($refund->fresh());
        } catch (Throwable $exception) {
            $refund->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ]);
            $this->fireCallback($refund->fresh());
        }
    }

    private function fireCallback(RefundRequest $refund): void
    {
        if (! $refund->callback_url) {
            return;
        }

        $body = [
            'refund_reference' => $refund->reference,
            'order_reference' => $refund->order_reference,
            'return_reference' => $refund->return_reference,
            'dispute_reference' => $refund->dispute_reference,
            'status' => $refund->status,
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'provider_key' => $refund->provider_key,
            'provider_reference' => $refund->provider_reference,
            'failure_reason' => $refund->failure_reason,
        ];

        try {
            Http::withHeaders([
                'X-Internal-Key' => env('MAIN_PLATFORM_INTERNAL_KEY')
                    ?: env('TRUST_MAIN_INTERNAL_KEY')
                    ?: config('services.main_platform.internal_key')
                    ?: env('INTERNAL_SERVICE_KEY'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(8)->post($refund->callback_url, $body);
        } catch (Throwable $exception) {
            Log::warning('[RefundRequest] Callback failed', [
                'refund_reference' => $refund->reference,
                'callback_url' => $refund->callback_url,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
