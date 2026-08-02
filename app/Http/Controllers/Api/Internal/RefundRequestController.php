<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\RefundRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'order_reference' => ['required', 'string', 'max:120'],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'max:8'],
            'reason' => ['required', 'string', 'max:5000'],
            'source_service' => ['nullable', 'string', 'max:80'],
            'return_reference' => ['nullable', 'string', 'max:120'],
            'dispute_reference' => ['nullable', 'string', 'max:120'],
            'requested_by_type' => ['nullable', 'string', 'max:80'],
            'requested_by_id' => ['nullable', 'string', 'max:120'],
            'callback_url' => ['nullable', 'url', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ]);

        [$refund, $idempotent, $remainingRefundable] = DB::transaction(function () use ($data) {
            $transaction = PaymentTransaction::query()
                ->with('paymentMethod')
                ->where('status', 'confirmed')
                ->when($data['transaction_reference'] ?? null, fn ($query, $reference) => $query->where('reference', $reference))
                ->when(empty($data['transaction_reference']), fn ($query) => $query->where('order_reference', $data['order_reference'])->latest('id'))
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                $existing = $this->existingRefundFor($data);

                if ($existing) {
                    return [$existing->load('transaction.paymentMethod'), true, null];
                }

                throw ValidationException::withMessages([
                    'transaction_reference' => 'No confirmed payment transaction was found for this refund.',
                ]);
            }

            $existing = $this->existingRefundFor($data);

            if ($existing) {
                return [$existing->load('transaction.paymentMethod'), true, null];
            }

            $reserved = (float) RefundRequest::query()
                ->where('payment_transaction_id', $transaction->id)
                ->whereNotIn('status', ['rejected'])
                ->sum('amount');

            $remaining = max(0, round((float) $transaction->amount - $reserved, 2));

            if ((float) $data['amount'] > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Refund amount cannot exceed the remaining refundable amount.',
                ]);
            }

            $metadata = array_merge($data['metadata'] ?? [], [
                'original_transaction_amount' => (float) $transaction->amount,
                'remaining_refundable_before_request' => $remaining,
            ]);

            $refund = RefundRequest::create([
                'reference' => RefundRequest::generateReference(),
                'payment_transaction_id' => $transaction->id,
                'order_reference' => $data['order_reference'],
                'source_service' => $data['source_service'] ?? 'trust',
                'return_reference' => $data['return_reference'] ?? null,
                'dispute_reference' => $data['dispute_reference'] ?? null,
                'requested_by_type' => $data['requested_by_type'] ?? null,
                'requested_by_id' => $data['requested_by_id'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? $transaction->currency,
                'provider_key' => $transaction->paymentMethod?->provider_key,
                'provider_reference' => $transaction->provider_reference,
                'callback_url' => $data['callback_url'] ?? null,
                'status' => 'requested',
                'reason' => $data['reason'],
                'metadata' => $metadata,
                'requested_at' => now(),
            ]);

            return [$refund->load('transaction.paymentMethod'), false, round($remaining - (float) $data['amount'], 2)];
        });

        return response()->json([
            'data' => [
                'refund' => $this->refundPayload($refund),
                'idempotent' => $idempotent,
                'remaining_refundable' => $remainingRefundable,
            ],
        ], $idempotent ? 200 : 201);
    }

    private function existingRefundFor(array $data): ?RefundRequest
    {
        $base = RefundRequest::query()
            ->where('order_reference', $data['order_reference'])
            ->where('source_service', $data['source_service'] ?? 'trust')
            ->whereNotIn('status', ['rejected']);

        if (! empty($data['return_reference'])) {
            $refund = (clone $base)
                ->where('return_reference', $data['return_reference'])
                ->latest('id')
                ->first();

            if ($refund) {
                return $refund;
            }
        }

        if (! empty($data['dispute_reference'])) {
            $refund = (clone $base)
                ->where('dispute_reference', $data['dispute_reference'])
                ->latest('id')
                ->first();

            if ($refund) {
                return $refund;
            }
        }

        if (! empty($data['requested_by_type']) && ! empty($data['requested_by_id'])) {
            return (clone $base)
                ->where('requested_by_type', $data['requested_by_type'])
                ->where('requested_by_id', $data['requested_by_id'])
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function refundPayload(RefundRequest $refund): array
    {
        return [
            'reference' => $refund->reference,
            'status' => $refund->status,
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'order_reference' => $refund->order_reference,
            'return_reference' => $refund->return_reference,
            'dispute_reference' => $refund->dispute_reference,
        ];
    }

    private function authorizeInternal(Request $request): void
    {
        $provided = (string) $request->header('X-Internal-Key', '');
        $expectedKeys = collect([
            config('services.main_platform.internal_key'),
            env('MAIN_PLATFORM_INTERNAL_KEY'),
            env('TRUST_MAIN_INTERNAL_KEY'),
            env('TOMS_INTERNAL_KEY'),
            env('PAYMENT_SERVICE_INTERNAL_KEY'),
            env('INTERNAL_SERVICE_KEY'),
        ])->filter()->values();

        abort_if($provided === '' || $expectedKeys->isEmpty() || ! $expectedKeys->contains(fn ($key) => hash_equals((string) $key, $provided)), 401, 'Unauthorized.');
    }
}
