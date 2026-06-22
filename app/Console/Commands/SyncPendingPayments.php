<?php

namespace App\Console\Commands;

use App\Models\EscrowWallet;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Services\PaymentRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncPendingPayments extends Command
{
    protected $signature = 'payments:sync-pending';
    protected $description = 'Sync pending payment transactions with providers';

    public function handle(PaymentRouter $router): int
    {
        Log::info('[SyncPendingPayments] Command started');

        $count = 0;

        PaymentTransaction::query()
            ->whereIn('status', ['pending', 'initiated'])
            ->whereNotNull('provider_reference')
            //->where('created_at', '>=', now()->subHours(2))
            ->with('paymentMethod')
            ->chunkById(50, function ($transactions) use ($router, &$count) {
                foreach ($transactions as $transaction) {
                    $count++;
                    $this->syncOne($transaction, $router);
                }
            });

        Log::info('[SyncPendingPayments] Command finished', [
            'transactions_checked' => $count,
        ]);

        $this->info("Checked {$count} pending transaction(s).");

        return self::SUCCESS;
    }

    private function syncOne(PaymentTransaction $transaction, PaymentRouter $router): void
    {
        Log::info('[SyncPendingPayments] Checking transaction', [
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'provider_reference' => $transaction->provider_reference,
            'order_reference' => $transaction->order_reference,
            'current_status' => $transaction->status,
        ]);

        try {
            if (! $transaction->paymentMethod) {
                Log::warning('[SyncPendingPayments] Missing payment method', [
                    'transaction_id' => $transaction->id,
                ]);
                return;
            }

            $driver = $router->driverFor($transaction->paymentMethod);

            Log::info('[SyncPendingPayments] Querying provider', [
                'transaction_id' => $transaction->id,
                'provider' => $transaction->paymentMethod->provider_key,
                'provider_reference' => $transaction->provider_reference,
            ]);

            $result = $driver->queryStatus($transaction->provider_reference);

            Log::info('[SyncPendingPayments] Provider response', [
                'transaction_id' => $transaction->id,
                'result' => $result,
            ]);

            $status = $result['status'] ?? 'pending';

            Log::info('[SyncPendingPayments] Status mapped', [
                'transaction_id' => $transaction->id,
                'internal_status' => $status,
                'raw_status' => $result['raw']['status']
                    ?? $result['raw']['paymentStatus']
                    ?? $result['raw']['state']
                    ?? $result['raw']['collectionStatus']
                    ?? null,
            ]);

            if (! in_array($status, ['confirmed', 'failed', 'cancelled'], true)) {
                Log::info('[SyncPendingPayments] Still pending', [
                    'transaction_id' => $transaction->id,
                    'status' => $status,
                ]);
                return;
            }

            DB::transaction(function () use ($transaction, $status, $result) {
                $transactionUpdates = [
                    'status' => $status,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'last_status_query' => $result,
                        'last_synced_at' => now()->toDateTimeString(),
                    ]),
                ];

                if ($status === 'confirmed') {
                    $transactionUpdates['confirmed_at'] = now();
                }

                if ($status === 'failed') {
                    $transactionUpdates['failed_at'] = now();
                }

                if ($status === 'cancelled') {
                    $transactionUpdates['cancelled_at'] = now();
                }

                $transaction->update($transactionUpdates);

                Log::info('[SyncPendingPayments] Transaction updated', [
                    'transaction_id' => $transaction->id,
                    'new_status' => $status,
                ]);

                $attempt = PaymentAttempt::where('payment_transaction_id', $transaction->id)
                    ->latest()
                    ->first();

                if ($attempt) {
                    $attemptUpdates = [
                        'status' => $status,
                        'metadata' => array_merge($attempt->metadata ?? [], [
                            'last_status_query' => $result,
                            'last_synced_at' => now()->toDateTimeString(),
                        ]),
                    ];

                    if ($status === 'confirmed') {
                        $attemptUpdates['confirmed_at'] = now();
                        $attemptUpdates['failure_reason'] = null;
                    }

                    if ($status === 'failed') {
                        $attemptUpdates['failed_at'] = now();
                        $attemptUpdates['failure_reason'] =
                            $result['raw']['message']
                            ?? $result['raw']['error']
                            ?? 'Payment failed.';
                    }

                    if ($status === 'cancelled') {
                        $attemptUpdates['cancelled_at'] = now();
                        $attemptUpdates['failure_reason'] =
                            $result['raw']['message']
                            ?? $result['raw']['error']
                            ?? 'Payment was cancelled.';
                    }

                    $attempt->update($attemptUpdates);

                    Log::info('[SyncPendingPayments] Attempt updated', [
                        'attempt_id' => $attempt->id,
                        'attempt_reference' => $attempt->attempt_reference,
                        'new_status' => $status,
                    ]);
                } else {
                    Log::warning('[SyncPendingPayments] No matching attempt found', [
                        'transaction_id' => $transaction->id,
                    ]);
                }

                if ($status === 'confirmed' && ! $transaction->escrowWallet) {
                    EscrowWallet::create([
                        'transaction_id' => $transaction->id,
                        'order_reference' => $transaction->order_reference,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'status' => 'holding',
                        'held_at' => now(),
                    ]);

                    Log::info('[SyncPendingPayments] Escrow wallet created', [
                        'transaction_id' => $transaction->id,
                        'order_reference' => $transaction->order_reference,
                    ]);
                }
            });

            $this->fireCallback($transaction->fresh());
        } catch (\Throwable $e) {
            Log::error('[SyncPendingPayments] Failed', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fireCallback(PaymentTransaction $transaction): void
    {
        if (! $transaction->callback_url) {
            Log::warning('[SyncPendingPayments] No callback URL', [
                'transaction_id' => $transaction->id,
            ]);
            return;
        }

        $eventMap = [
            'confirmed' => 'payment.confirmed',
            'failed' => 'payment.failed',
            'cancelled' => 'payment.cancelled',
        ];

        $event = $eventMap[$transaction->status] ?? null;

        if (! $event) {
            Log::info('[SyncPendingPayments] No callback event for status', [
                'transaction_id' => $transaction->id,
                'status' => $transaction->status,
            ]);
            return;
        }

        $body = [
            'event' => $event,
            'order_reference' => $transaction->order_reference,
            'transaction_reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
        ];

        $signature = hash_hmac(
            'sha256',
            json_encode($body, JSON_UNESCAPED_SLASHES),
            (string) config('services.main_platform.callback_secret')
        );

        Log::info('[SyncPendingPayments] Sending callback', [
            'transaction_id' => $transaction->id,
            'callback_url' => $transaction->callback_url,
            'body' => $body,
        ]);

        try {
            $response = Http::withHeaders([
                'X-Payment-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post($transaction->callback_url, $body);

            Log::info('[SyncPendingPayments] Callback response', [
                'transaction_id' => $transaction->id,
                'http_status' => $response->status(),
                'response_body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[SyncPendingPayments] Callback failed', [
                'transaction_id' => $transaction->id,
                'callback_url' => $transaction->callback_url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}