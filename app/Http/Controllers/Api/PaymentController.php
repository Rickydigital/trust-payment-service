<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitiatePaymentRequest;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Services\PaymentRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'subtotal' => $data['subtotal'] ?? null,
                'shipping_fee' => $data['shipping_fee'] ?? null,
                'seller_id' => $data['seller_id'] ?? null,
                'seller_payout_account' => $this->buildSellerPayoutAccount($data),
                'delivery_service_id' => $data['delivery_service_id'] ?? null,
                'delivery_payout_account' => $data['delivery_payout_account'] ?? (
                    $data['delivery_payout_phone'] ?? null
                        ? ['method' => 'mobile', 'phone' => $data['delivery_payout_phone']]
                        : null
                ),
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

        // Terminal states never change — no point re-querying the provider.
        if (in_array($transaction->status, ['pending', 'initiated'], true) && $transaction->provider_reference) {
            $transaction = $this->refreshFromProvider($transaction);
        }

        return response()->json([
            'success' => true,
            'transaction_reference' => $transaction->reference,
            'status' => $transaction->status,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'provider_key' => $transaction->paymentMethod->provider_key,
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

    private function makeReference(): string
    {
        do {
            $candidate = 'PAY-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (PaymentTransaction::where('reference', $candidate)->exists());

        return $candidate;
    }
}