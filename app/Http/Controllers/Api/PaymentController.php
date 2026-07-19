<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitiatePaymentRequest;
use App\Models\EscrowWallet;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Services\PaymentRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentRouter $router
    ) {}

    public function methods(): JsonResponse
    {
        $methods = $this->router->activeMethods();

        return response()->json([
            'success' => true,
            'methods' => $methods->map(fn ($m) => [
                'provider_key' => $m->provider_key,
                'display_name' => $m->display_name,
                'logo_url'     => $m->logo_url,
                'type'         => $m->type,
                'sort_order'   => $m->sort_order,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /initiate
    // ─────────────────────────────────────────────

    public function initiate(InitiatePaymentRequest $request): JsonResponse
{
    $data = $request->validated();

    $authUser = $request->attributes->get('auth_user');
    $isInternalCall = $request->attributes->get('is_internal_call', false);

    $method = $this->router->method($data['provider_key']);

    if (! $method->is_active) {
        throw ValidationException::withMessages([
            'provider_key' => 'This payment method is not currently available.',
        ]);
    }

    if ($method->provider && ! $method->provider->is_active) {
        throw ValidationException::withMessages([
            'provider_key' => 'This payment provider is not currently available.',
        ]);
    }

    if ($method->type === 'mobile_money' && empty($data['payer_phone'])) {
        throw ValidationException::withMessages([
            'payer_phone' => 'Phone number is required for this payment method.',
        ]);
    }

    return DB::transaction(function () use ($data, $method, $authUser, $isInternalCall) {
        $attempt = PaymentAttempt::create([
            'order_reference' => $data['order_reference'],
            'attempt_reference' => PaymentAttempt::generateReference(),
            'provider_key' => $method->provider_key,
            'status' => 'initiated',
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'payer_phone' => $data['payer_phone'] ?? null,
            'initiated_at' => now(),
            'metadata' => [
                'initiated_by_user_id' => $authUser['id'] ?? null,
                'is_internal_call' => $isInternalCall,
                'payment_context' => $data['payment_context'] ?? 'marketplace_order',
                'source_service' => $data['source_service'] ?? 'trust',
            ],
        ]);

        $transaction = PaymentTransaction::create([
            'reference' => $this->makeReference(),
            'order_reference' => $data['order_reference'],
            'payment_method_id' => $method->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'status' => 'initiated',
            'phone' => $data['payer_phone'] ?? null,
            'payer_name' => $data['payer_name'] ?? ($authUser['name'] ?? null),
            'callback_url' => $data['callback_url'],
            'metadata' => [
                'attempt_reference' => $attempt->attempt_reference,
                'initiated_by_user_id' => $authUser['id'] ?? null,
                'is_internal_call' => $isInternalCall,
                'payment_context' => $data['payment_context'] ?? 'marketplace_order',
                'source_service' => $data['source_service'] ?? 'trust',
                'subtotal' => $data['subtotal'] ?? null,
                'shipping_fee' => $data['shipping_fee'] ?? null,
                'order_splits' => $this->normalizeOrderSplits($data),
                'seller_id' => $data['seller_id'] ?? null,
                'seller_payout_account' => $this->buildSellerPayoutAccount($data),
                'delivery_service_id' => $data['delivery_service_id'] ?? null,
                'delivery_payout_account' => $data['delivery_payout_account'] ?? (
                    $data['delivery_payout_phone'] ?? null
                        ? ['method' => 'mobile', 'phone' => $data['delivery_payout_phone']]
                        : null
                ),
                'trust_deal_id' => $data['trust_deal_id'] ?? null,
                'trust_buyer_id' => $data['trust_buyer_id'] ?? null,
                'trust_seller_id' => $data['trust_seller_id'] ?? null,
                'trust_recipient_type' => $data['trust_recipient_type'] ?? 'user',
                'trust_recipient_account' => $this->buildTrustRecipientAccount($data),
            ],
        ]);

        $attempt->update([
            'payment_transaction_id' => $transaction->id,
        ]);

        $driver = $this->router->driverFor($method);

        $result = $driver->initiate([
            'amount' => (float) $data['amount'],
            'currency' => $data['currency'],
            'orderReference' => $transaction->reference,
            'payerPhone' => $data['payer_phone'] ?? null,
            'payerName' => $data['payer_name'] ?? null,
        ]);

        if (! ($result['ok'] ?? false)) {
            $transaction->update([
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'driver_response' => $result,
                ]),
            ]);

            $attempt->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $result['message'] ?? 'Payment initiation failed.',
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'driver_response' => $result,
                ]),
            ]);

            return response()->json([
                'success' => false,
                'attempt_reference' => $attempt->attempt_reference,
                'transaction_reference' => $transaction->reference,
                'status' => 'failed',
                'message' => $result['message'] ?? 'Payment initiation failed.',
            ], 422);
        }

        $transaction->update([
            'status' => 'pending',
            'provider_reference' => $result['providerReference'] ?? null,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'driver_response' => $result,
            ]),
        ]);

        $attempt->update([
            'status' => 'pending',
            'metadata' => array_merge($attempt->metadata ?? [], [
                'driver_response' => $result,
            ]),
        ]);

        return response()->json([
            'success' => true,
            'attempt_reference' => $attempt->attempt_reference,
            'transaction_reference' => $transaction->reference,
            'status' => $transaction->status,
            'message' => $result['message'] ?? 'Check your phone and approve the payment.',
        ], 201);
    });
}

public function attempts(Request $request, string $orderReference): JsonResponse
{
    $authUser = $request->attributes->get('auth_user');

    $attempts = PaymentAttempt::query()
        ->where('order_reference', $orderReference)
        ->where('metadata->initiated_by_user_id', $authUser['id'] ?? null)
        ->with('transaction.paymentMethod')
        ->latest()
        ->get();

    return response()->json([
        'success' => true,
        'order_reference' => $orderReference,
        'attempts' => $attempts->map(fn ($attempt) => [
            'attempt_reference' => $attempt->attempt_reference,
            'transaction_reference' => $attempt->transaction?->reference,
            'provider_key' => $attempt->provider_key,
            'provider_name' => $attempt->transaction?->paymentMethod?->display_name,
            'status' => $attempt->status,
            'amount' => (float) $attempt->amount,
            'currency' => $attempt->currency,
            'payer_phone' => $attempt->payer_phone,
            'failure_reason' => $attempt->failure_reason,
            'initiated_at' => $attempt->initiated_at,
            'confirmed_at' => $attempt->confirmed_at,
            'failed_at' => $attempt->failed_at,
            'cancelled_at' => $attempt->cancelled_at,
            'created_at' => $attempt->created_at,
        ]),
    ]);
}

    // ─────────────────────────────────────────────
    // GET /status/{transaction_reference}
    // ─────────────────────────────────────────────

    public function status(Request $request, string $transactionReference): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');

        $transaction = PaymentTransaction::query()
            ->where('reference', $transactionReference)
            ->with('paymentMethod')
            ->first();

        if (! $transaction) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'Transaction not found.',
            ]);
        }

        $initiatedBy = $transaction->metadata['initiated_by_user_id'] ?? null;

        if ($initiatedBy && (string) $initiatedBy !== (string) ($authUser['id'] ?? null)) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'Transaction not found.',
            ]);
        }

        $previousStatus = $transaction->status;

        // Terminal states never change — no point re-querying the provider.
        if (in_array($transaction->status, ['pending', 'initiated'], true) && $transaction->provider_reference) {
            $transaction = $this->refreshFromProvider($transaction);
        }

        if ($previousStatus !== $transaction->status && in_array($transaction->status, ['confirmed', 'failed', 'cancelled'], true)) {
            if ($transaction->status === 'confirmed') {
                $this->createEscrowWallets($transaction);
            }

            $this->fireCallback($transaction);
        }

        return response()->json([
            'success' => true,
            'transaction_reference' => $transaction->reference,
            'status' => $transaction->status,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'provider_key' => $transaction->paymentMethod->provider_key,
            'payment_context' => $transaction->metadata['payment_context'] ?? null,
            'source_service' => $transaction->metadata['source_service'] ?? null,
            'created_at' => $transaction->created_at,
            'confirmed_at' => $transaction->confirmed_at,
        ]);
    }

    private function refreshFromProvider(PaymentTransaction $transaction): PaymentTransaction
    {
        try {
            $driver = $this->router->driverFor($transaction->paymentMethod);
            $result = $driver->queryStatus($transaction->provider_reference);
        } catch (\Throwable $e) {
            // Provider query failed — fall back to whatever we already
            // have in the DB rather than surfacing a 500 to the buyer
            // who is just polling for status.
            return $transaction;
        }

        $status = $result['status'] ?? 'pending';

        if ($status === $transaction->status) {
            return $transaction;
        }

        $updates = [
            'status' => $status,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'last_status_query' => $result,
            ]),
        ];

        if ($status === 'confirmed') {
            $updates['confirmed_at'] = now();
        }

        if ($status === 'failed') {
            $updates['failed_at'] = now();
        }

        $transaction->update($updates);

        return $transaction->fresh();
    }

    private function buildSellerPayoutAccount(array $data): ?array
    {
        // Server-to-server calls (CheckoutController -> PaymentService)
        // send a pre-built array. Normalize its key names to our internal
        // shape (ProcessPayoutJob looks for 'phone' on mobile accounts).
        if (! empty($data['seller_payout_account']) && is_array($data['seller_payout_account'])) {
            $account = $data['seller_payout_account'];
            $method = $account['method'] ?? null;

            if ($method === 'mobile' && ! empty($account['mobile_phone'])) {
                return [
                    'method' => 'mobile',
                    'phone' => $account['mobile_phone'],
                    'mobile_network' => $account['mobile_network'] ?? null,
                ];
            }

            if ($method === 'bank' && ! empty($account['account_number'])) {
                return [
                    'method' => 'bank',
                    'bank_name' => $account['bank_name'] ?? null,
                    'account_name' => $account['account_name'] ?? null,
                    'account_number' => $account['account_number'] ?? null,
                ];
            }

            return null;
        }

        // Fallback: flat fields, for direct/manual calls.
        $method = $data['seller_payout_method'] ?? null;

        if ($method === 'mobile' && ! empty($data['seller_payout_phone'])) {
            return [
                'method' => 'mobile',
                'phone' => $data['seller_payout_phone'],
            ];
        }

        if ($method === 'bank' && ! empty($data['seller_bank_account_number'])) {
            return [
                'method' => 'bank',
                'bank_name' => $data['seller_bank_name'] ?? null,
                'account_name' => $data['seller_bank_account_name'] ?? null,
                'account_number' => $data['seller_bank_account_number'] ?? null,
            ];
        }

        return null;
    }

    private function buildTrustRecipientAccount(array $data): ?array
    {
        if (! empty($data['trust_recipient_account']) && is_array($data['trust_recipient_account'])) {
            $account = $data['trust_recipient_account'];
            $method = $account['method'] ?? null;

            if ($method === 'mobile' && (! empty($account['phone']) || ! empty($account['mobile_phone']))) {
                return [
                    'method' => 'mobile',
                    'phone' => $account['phone'] ?? $account['mobile_phone'],
                    'mobile_phone' => $account['mobile_phone'] ?? $account['phone'],
                    'mobile_network' => $account['mobile_network'] ?? null,
                    'account_name' => $account['account_name'] ?? null,
                ];
            }

            if ($method === 'bank' && ! empty($account['account_number'])) {
                return [
                    'method' => 'bank',
                    'bank_name' => $account['bank_name'] ?? null,
                    'account_name' => $account['account_name'] ?? null,
                    'account_number' => $account['account_number'],
                ];
            }

            if ($method === 'wallet') {
                return $account;
            }
        }

        return $this->buildSellerPayoutAccount($data);
    }

    private function normalizeOrderSplits(array $data): ?array
    {
        if (empty($data['order_splits']) || ! is_array($data['order_splits'])) {
            return null;
        }

        return collect($data['order_splits'])
            ->map(fn (array $split) => [
                'order_reference' => (string) $split['order_reference'],
                'amount' => round((float) ($split['amount'] ?? 0), 2),
                'subtotal' => round((float) ($split['subtotal'] ?? 0), 2),
                'shipping_fee' => round((float) ($split['shipping_fee'] ?? 0), 2),
                'seller_id' => isset($split['seller_id']) ? (string) $split['seller_id'] : null,
                'delivery_service_id' => isset($split['delivery_service_id']) ? (string) $split['delivery_service_id'] : null,
                'delivery_required' => filter_var($split['delivery_required'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            ])
            ->filter(fn (array $split) => $split['order_reference'] !== '' && $split['amount'] > 0)
            ->values()
            ->all();
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

    private function fireCallback(PaymentTransaction $transaction): void
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
            Log::error('Callback to main platform failed from status polling', [
                'transaction_reference' => $transaction->reference,
                'callback_url' => $transaction->callback_url,
                'error' => $e->getMessage(),
            ]);
        }
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

    private function makeReference(): string
    {
        do {
            $candidate = 'PAY-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (PaymentTransaction::where('reference', $candidate)->exists());

        return $candidate;
    }
}
