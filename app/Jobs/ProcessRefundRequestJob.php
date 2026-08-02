<?php

namespace App\Jobs;

use App\Models\RefundRequest;
use App\Services\PaymentRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessRefundRequestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

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
                'provider_message' => 'Refund cannot be processed automatically because transaction/provider reference is missing.',
                'failure_reason' => 'Refund cannot be processed automatically because transaction/provider reference is missing.',
            ]);
            $this->fireCallback($refund->fresh());

            return;
        }

        try {
            $providerRequest = [
                'provider_reference' => $transaction->provider_reference,
                'refund_reference' => $refund->reference,
                'order_reference' => $refund->order_reference,
                'amount' => (float) $refund->amount,
                'currency' => $refund->currency,
            ];

            $refund->update([
                'status' => 'processing',
                'processed_at' => now(),
                'provider_key' => $method->provider_key,
                'provider_request' => $providerRequest,
                'provider_message' => null,
                'failure_reason' => null,
            ]);

            $driver = $router->driverFor($method);
            $result = $driver->refund($transaction->provider_reference, (float) $refund->amount);
            $ok = (bool) ($result['ok'] ?? false);
            $status = $result['status'] ?? null;
            $providerResponse = $this->sanitizeProviderResponse($result);
            $providerMessage = $this->messageFrom($result['message'] ?? $status ?? 'Provider refund response received.');

            if ($ok && in_array($status, ['completed', 'confirmed', 'success'], true)) {
                $refund->update([
                    'status' => 'completed',
                    'provider_response' => $providerResponse,
                    'provider_message' => $providerMessage,
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
                    'provider_response' => $providerResponse,
                    'provider_message' => $providerMessage,
                    'provider_reference' => $result['providerReference'] ?? $refund->provider_reference,
                    'failure_reason' => null,
                ]);
                $this->fireCallback($refund->fresh());

                return;
            }

            $message = $this->messageFrom($result['message'] ?? 'Provider refund was not accepted.');
            $manual = str_contains(strtolower($message), 'not supported') || str_contains(strtolower($message), 'manual');

            $refund->update([
                'status' => $manual ? 'manual_review' : 'failed',
                'provider_response' => $providerResponse,
                'provider_message' => $message,
                'failure_reason' => $message,
                'failed_at' => $manual ? null : now(),
            ]);
            $this->fireCallback($refund->fresh());
        } catch (Throwable $exception) {
            $refund->update([
                'status' => 'failed',
                'provider_message' => $exception->getMessage(),
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
            $response = Http::withHeaders([
                'X-Internal-Key' => env('MAIN_PLATFORM_INTERNAL_KEY')
                    ?: env('TRUST_MAIN_INTERNAL_KEY')
                    ?: config('services.main_platform.internal_key')
                    ?: env('INTERNAL_SERVICE_KEY'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(8)->post($refund->callback_url, $body);

            $refund->update([
                'callback_attempted_at' => now(),
                'callback_status' => $response->successful() ? 'sent' : 'failed',
                'callback_response' => [
                    'http_status' => $response->status(),
                    'body' => Str::limit($response->body(), 2000),
                ],
                'callback_error' => $response->successful() ? null : Str::limit($response->body(), 1000),
            ]);
        } catch (Throwable $exception) {
            $refund->update([
                'callback_attempted_at' => now(),
                'callback_status' => 'failed',
                'callback_error' => $exception->getMessage(),
            ]);

            Log::warning('[RefundRequest] Callback failed', [
                'refund_reference' => $refund->reference,
                'callback_url' => $refund->callback_url,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function sanitizeProviderResponse(mixed $value): array
    {
        if (! is_array($value)) {
            return $value === null ? [] : ['value' => $this->messageFrom($value)];
        }

        return $this->sanitizeArray($value);
    }

    private function sanitizeArray(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/authorization|token|secret|password|api[_-]?key/i', $key)) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeArray($value);
                continue;
            }

            $clean[$key] = is_scalar($value) || $value === null ? $value : $this->messageFrom($value);
        }

        return $clean;
    }

    private function messageFrom(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_bool($message)) {
            return $message ? 'true' : 'false';
        }

        if (is_scalar($message)) {
            return (string) $message;
        }

        if (is_array($message)) {
            return json_encode($message, JSON_UNESCAPED_SLASHES) ?: 'Provider refund response was not accepted.';
        }

        if (is_object($message) && method_exists($message, '__toString')) {
            return (string) $message;
        }

        return 'Provider refund response was not accepted.';
    }
}
