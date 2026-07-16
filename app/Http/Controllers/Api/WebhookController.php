<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EscrowWallet;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\WebhookLog;
use App\Services\PaymentRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentRouter $router
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        // {provider} must match a known provider_key, or 404 immediately —
        // per spec, before any logging or verification happens.
        try {
            $method = $this->router->method($provider);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Unknown provider.',
            ], 404);
        }

        $payload = $request->all();
        $signatureHeader = (string) ($payload['checksum'] ?? $request->header('X-Signature', ''));

        $log = WebhookLog::create([
            'provider' => $provider,
            'endpoint' => $request->path(),
            'payload' => $payload,
            'signature_valid' => false,
            'processing_status' => 'received',
        ]);

        $driver = $this->router->driverFor($method);

        $signatureValid = false;

        try {
            $signatureValid = $driver->verifyWebhook($payload, $signatureHeader);
        } catch (\Throwable $e) {
            Log::error('Webhook signature verification threw', [
                'provider' => $provider,
                'webhook_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }

        $log->update(['signature_valid' => $signatureValid]);

        if (! $signatureValid) {
            $log->update([
                'processing_status' => 'failed',
                'error_message' => 'Signature verification failed.',
                'processed_at' => now(),
            ]);

            // Per spec: verification failure returns 400, not 200 — this is
            // the one case where we don't tell the provider "stop retrying",
            // since a failed signature could also mean a config problem on
            // our side worth them retrying after we fix it.
            return response()->json([
                'ok' => false,
                'message' => 'Signature verification failed.',
            ], 400);
        }

        try {
            $normalized = $driver->normalizeWebhookPayload($payload);
        } catch (\Throwable $e) {
            $log->update([
                'processing_status' => 'failed',
                'error_message' => 'Failed to normalize payload: ' . $e->getMessage(),
                'processed_at' => now(),
            ]);

            // Still 200 — malformed payload from a verified provider isn't
            // something retrying will fix, and per spec we always return
            // 200 for cases the provider shouldn't keep retrying.
            return response()->json([
                'ok' => true,
                'message' => 'Webhook received but could not be processed.',
            ]);
        }

        $transaction = $normalized['orderReference']
            ? PaymentTransaction::query()
                ->whereRaw(
                    "REPLACE(REPLACE(reference, '-', ''), ' ', '') = ?",
                    [preg_replace('/[^A-Za-z0-9]/', '', $normalized['orderReference'])],
                    'and'
                )
                ->first()
            : null;

        if (! $transaction) {
            $log->update([
                'processing_status' => 'ignored',
                'error_message' => 'No matching transaction for order reference.',
                'processed_at' => now(),
            ]);

            // Always 200 for unmatched references so the provider stops retrying.
            return response()->json([
                'ok' => true,
                'message' => 'Webhook received but no matching transaction found.',
            ]);
        }

        $log->update(['matched_transaction_id' => $transaction->id]);

        DB::transaction(function () use ($transaction, $normalized, $log) {
            $this->applyStatusUpdate($transaction, $normalized);

            $log->update([
                'processing_status' => 'processed',
                'processed_at' => now(),
            ]);
        });

        $this->fireCallback($transaction->fresh(), $normalized);

        return response()->json([
            'ok' => true,
            'message' => 'Webhook processed.',
        ]);
    }

   private function applyStatusUpdate(PaymentTransaction $transaction, array $normalized): void
{
    $status = $normalized['status'];

    $attempt = PaymentAttempt::query()
        ->where('payment_transaction_id', $transaction->id)
        ->first();

    if ($transaction->status === $status) {
        if ($attempt && $attempt->status !== $status) {
            $this->updateAttemptStatus($attempt, $status, $normalized);
        }

        if ($status === 'confirmed') {
            $this->createEscrowWallets($transaction);
        }

        return;
    }

    $updates = [
        'status' => $status,
        'metadata' => array_merge($transaction->metadata ?? [], [
            'last_webhook_payload' => $normalized['metadata'],
        ]),
    ];

    if ($status === 'confirmed') {
        $updates['confirmed_at'] = now();
    }

    if ($status === 'failed') {
        $updates['failed_at'] = now();
    }

    $transaction->update($updates);

    if ($attempt) {
        $this->updateAttemptStatus($attempt, $status, $normalized);
    }

    if ($status === 'confirmed') {
        $this->createEscrowWallets($transaction);
    }
}

private function updateAttemptStatus(
    PaymentAttempt $attempt,
    string $status,
    array $normalized
): void {
    $updates = [
        'status' => $status,
        'metadata' => array_merge($attempt->metadata ?? [], [
            'last_webhook_payload' => $normalized['metadata'],
        ]),
    ];

    if ($status === 'confirmed') {
        $updates['confirmed_at'] = now();
    }

    if ($status === 'failed') {
        $updates['failed_at'] = now();
        $updates['failure_reason'] =
            $normalized['metadata']['message']
            ?? $normalized['metadata']['error']
            ?? 'Payment failed.';
    }

    if ($status === 'cancelled') {
        $updates['cancelled_at'] = now();
        $updates['failure_reason'] = 'Payment was cancelled.';
    }

    $attempt->update($updates);
}

    private function fireCallback(PaymentTransaction $transaction, array $normalized): void
    {
        if (! $transaction->callback_url) {
            return;
        }

        $eventMap = [
            'confirmed' => 'payment.confirmed',
            'failed' => 'payment.failed',
            'cancelled' => 'payment.cancelled',
        ];

        $event = $eventMap[$transaction->status] ?? null;

        if (! $event) {
            return;
        }

        $body = [
            'event' => $event,
            'order_reference' => $transaction->order_reference,
            'order_references' => $this->orderReferences($transaction),
            'transaction_reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'payer_phone' => $transaction->phone,
            'provider_key' => $transaction->paymentMethod?->provider_key,
            'provider_type' => $transaction->paymentMethod?->type,
            'payment_context' => $transaction->metadata['payment_context'] ?? null,
            'source_service' => $transaction->metadata['source_service'] ?? null,
        ];

        $signature = hash_hmac(
            'sha256',
            json_encode($body, JSON_UNESCAPED_SLASHES),
            (string) config('services.main_platform.callback_secret')
        );

        try {
            Http::withHeaders([
                'X-Payment-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->timeout(5)->post($transaction->callback_url, $body);
        } catch (\Throwable $e) {
            Log::error('Callback to main platform failed', [
                'transaction_reference' => $transaction->reference,
                'callback_url' => $transaction->callback_url,
                'error' => $e->getMessage(),
            ]);
            // Per spec this should go through the dedicated "callbacks"
            // queue with retry/backoff — handled when we build the queue
            // worker. For now a failure here is logged, not retried inline.
        }
    }

    private function createEscrowWallets(PaymentTransaction $transaction): void
    {
        $splits = $transaction->metadata['order_splits'] ?? [];

        if (is_array($splits) && ! empty($splits)) {
            foreach ($splits as $split) {
                if (! is_array($split) || empty($split['order_reference'])) {
                    continue;
                }

                EscrowWallet::firstOrCreate(
                    [
                        'transaction_id' => $transaction->id,
                        'order_reference' => (string) $split['order_reference'],
                    ],
                    [
                        'amount' => round((float) ($split['amount'] ?? 0), 2),
                        'currency' => $transaction->currency,
                        'status' => 'holding',
                        'held_at' => now(),
                    ]
                );
            }

            return;
        }

        EscrowWallet::firstOrCreate(
            [
                'transaction_id' => $transaction->id,
                'order_reference' => $transaction->order_reference,
            ],
            [
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'holding',
                'held_at' => now(),
            ]
        );
    }

    private function orderReferences(PaymentTransaction $transaction): array
    {
        $splits = $transaction->metadata['order_splits'] ?? [];

        if (is_array($splits) && ! empty($splits)) {
            return collect($splits)
                ->map(fn ($split) => is_array($split) ? ($split['order_reference'] ?? null) : null)
                ->filter()
                ->map(fn ($reference) => (string) $reference)
                ->unique()
                ->values()
                ->all();
        }

        return [$transaction->order_reference];
    }
}
