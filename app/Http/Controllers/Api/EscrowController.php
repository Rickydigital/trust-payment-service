<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EscrowSplit;
use App\Models\EscrowWallet;
use App\Models\FeeSetting;
use App\Models\PayoutJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EscrowController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /escrow/{order_reference}
    // ─────────────────────────────────────────────

    public function show(string $orderReference): JsonResponse
    {
        $wallet = EscrowWallet::query()
            ->where('order_reference', $orderReference)
            ->with('splits')
            ->first();

        if (! $wallet) {
            throw ValidationException::withMessages([
                'order_reference' => 'No escrow found for this order reference.',
            ]);
        }

        return response()->json([
            'success' => true,
            'order_reference' => $wallet->order_reference,
            'status' => $wallet->status,
            'amount' => (float) $wallet->amount,
            'currency' => $wallet->currency,
            'held_at' => $wallet->held_at,
            'release_requested_at' => $wallet->release_requested_at,
            'released_at' => $wallet->released_at,
            'splits' => $wallet->status === 'released' || $wallet->status === 'releasing'
                ? $wallet->splits->map(fn ($split) => [
                    'recipient_type' => $split->recipient_type,
                    'recipient_id' => $split->recipient_id,
                    'amount' => (float) $split->amount,
                    'currency' => $split->currency,
                    'payout_job_id' => $split->payout_job_id,
                ])
                : [],
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /escrow/release
    // ─────────────────────────────────────────────

    public function release(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_reference' => ['required', 'string', 'max:64'],
        ]);

        $wallet = EscrowWallet::query()
            ->where('order_reference', $data['order_reference'])
            ->with('transaction')
            ->first();

        if (! $wallet) {
            throw ValidationException::withMessages([
                'order_reference' => 'No escrow found for this order reference.',
            ]);
        }

        if ($wallet->status !== 'holding') {
            throw ValidationException::withMessages([
                'order_reference' => "Escrow cannot be released from its current status: {$wallet->status}.",
            ]);
        }

        $breakdown = $this->calculateSplits($wallet);

        return DB::transaction(function () use ($wallet, $breakdown) {
            $splits = [];

            foreach ($breakdown as $entry) {
                if ($entry['amount'] <= 0) {
                    continue;
                }

                $splits[] = EscrowSplit::create([
                    'escrow_wallet_id' => $wallet->id,
                    'recipient_type' => $entry['recipient_type'],
                    'recipient_id' => $entry['recipient_id'],
                    'recipient_account' => $entry['recipient_account'],
                    'amount' => $entry['amount'],
                    'gross_amount' => $entry['gross_amount'],
                    'platform_fee' => $entry['platform_fee'],
                    'net_amount' => $entry['net_amount'],
                    'currency' => $wallet->currency,
                    'status' => $entry['recipient_type'] === 'platform' ? 'paid' : 'available',
                    'available_at' => $entry['recipient_type'] === 'platform' ? null : now(),
                    'released_at' => now(),
                ]);
            }

            foreach ($splits as $split) {
                // platform splits have no external payout — funds simply
                // stay with the platform's own merchant balance, no
                // disbursement needed.
                if ($split->recipient_type === 'platform') {
                    continue;
                }

                // Delivery service payouts aren't built on the main
                // platform yet — if no payout account was provided at
                // /initiate time, leave this split unqueued rather than
                // create a payout job that can never actually be paid.
                // The split row itself still exists so the money is
                // accounted for and visible via GET /escrow/{ref}.
                if (! $split->recipient_account) {
                    continue;
                }

                $payoutJob = PayoutJob::create([
                    'reference' => $this->makePayoutReference(),
                    'escrow_split_id' => $split->id,
                    'recipient_type' => $split->recipient_type,
                    'recipient_id' => $split->recipient_id,
                    'amount' => $split->amount,
                    'currency' => $split->currency,
                    'provider_key' => 'clickpesa', // default payout driver for now
                    'status' => 'queued',
                ]);

                $split->update(['payout_job_id' => $payoutJob->id]);
                $split->update(['status' => 'withdraw_pending']);

                \App\Jobs\ProcessPayoutJob::dispatch($payoutJob->id)
                    ->onQueue('payments');
            }

            $wallet->update([
                'status' => 'releasing',
                'release_requested_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Escrow release queued.',
                'order_reference' => $wallet->order_reference,
                'status' => $wallet->status,
                'splits' => collect($splits)->map(fn ($s) => [
                    'recipient_type' => $s->recipient_type,
                    'recipient_id' => $s->recipient_id,
                    'amount' => (float) $s->amount,
                    'currency' => $s->currency,
                ]),
            ]);
        });
    }

    public function releaseSeller(Request $request, string $orderReference): JsonResponse
    {
        return $this->releaseRecipient($request, $orderReference, 'seller');
    }

    public function releaseDelivery(Request $request, string $orderReference): JsonResponse
    {
        return $this->releaseRecipient($request, $orderReference, 'delivery_service');
    }

    public function releaseUser(Request $request, string $orderReference): JsonResponse
    {
        return $this->releaseRecipient($request, $orderReference, 'user');
    }

    private function releaseRecipient(Request $request, string $orderReference, string $recipientType): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => ['nullable', 'string', 'max:80'],
            'recipient_account' => ['nullable', 'array'],
        ]);

        $wallet = EscrowWallet::query()
            ->where('order_reference', $orderReference)
            ->with(['transaction', 'splits'])
            ->first();

        if (! $wallet) {
            throw ValidationException::withMessages([
                'order_reference' => 'No escrow found for this order reference.',
            ]);
        }

        return DB::transaction(function () use ($wallet, $recipientType, $data) {
            $wallet = EscrowWallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->with(['transaction', 'splits'])
                ->firstOrFail();

            if ($wallet->splits->isEmpty()) {
                foreach ($this->calculateSplits($wallet) as $entry) {
                    if ($entry['amount'] <= 0) {
                        continue;
                    }

                    EscrowSplit::create([
                        'escrow_wallet_id' => $wallet->id,
                        'recipient_type' => $entry['recipient_type'],
                        'recipient_id' => $entry['recipient_id'],
                        'recipient_account' => $entry['recipient_account'],
                        'amount' => $entry['amount'],
                        'gross_amount' => $entry['gross_amount'],
                        'platform_fee' => $entry['platform_fee'],
                        'net_amount' => $entry['net_amount'],
                        'currency' => $wallet->currency,
                        'status' => $entry['recipient_type'] === 'platform' ? 'paid' : 'held',
                        'released_at' => $entry['recipient_type'] === 'platform' ? now() : null,
                        'paid_at' => $entry['recipient_type'] === 'platform' ? now() : null,
                    ]);
                }

                $wallet->load('splits');
            }

            $released = [];

            foreach ($wallet->splits->where('recipient_type', $recipientType) as $split) {
                $identityUpdates = [];
                if (! empty($data['recipient_id']) && empty($split->recipient_id)) {
                    $identityUpdates['recipient_id'] = $data['recipient_id'];
                }
                if (! empty($data['recipient_account']) && empty($split->recipient_account)) {
                    $identityUpdates['recipient_account'] = $data['recipient_account'];
                }

                if (! in_array($split->status, ['held'], true)) {
                    if ($identityUpdates) {
                        $split->update($identityUpdates);
                        $split = $split->fresh();
                    }
                    $released[] = $split;
                    continue;
                }

                $split->update([
                    ...$identityUpdates,
                    'status' => 'available',
                    'available_at' => now(),
                    'released_at' => now(),
                ]);
                $released[] = $split->fresh();
            }

            $wallet->update([
                'status' => 'releasing',
                'release_requested_at' => $wallet->release_requested_at ?? now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$recipientType} funds released to available balance.",
                'order_reference' => $wallet->order_reference,
                'released' => collect($released)->map(fn (EscrowSplit $split) => [
                    'recipient_type' => $split->recipient_type,
                    'recipient_id' => $split->recipient_id,
                    'status' => $split->status,
                    'gross_amount' => (float) ($split->gross_amount ?: $split->amount),
                    'platform_fee' => (float) $split->platform_fee,
                    'net_amount' => (float) ($split->net_amount ?: $split->amount),
                    'currency' => $split->currency,
                ])->values(),
            ]);
        });
    }

    /**
     * Calculate the three-way split: seller, delivery_service, platform.
     *
     * seller amount    = subtotal - (subtotal * seller_fee_percent / 100)
     * delivery amount  = shipping_fee, paid as-is to the delivery service
     * platform amount  = whatever remains of the total held amount
     *                     (buyer fee + seller fee land here)
     *
     * subtotal/shipping_fee come from payment_transactions.metadata,
     * captured at /initiate time — NOT stored on escrow_wallets itself,
     * since the spec's escrow_wallets table has no room for them.
     */
    private function calculateSplits(EscrowWallet $wallet): array
    {
        $transaction = $wallet->transaction;
        $metadata = $transaction->metadata ?? [];
        $walletMetadata = $this->metadataForWallet($wallet, $metadata);

        if (($metadata['payment_context'] ?? null) === 'trust_deal') {
            return $this->calculateTrustDealSplits($wallet, $metadata);
        }

        $subtotal = (float) ($walletMetadata['subtotal'] ?? $metadata['subtotal'] ?? 0);
        $shippingFee = (float) ($walletMetadata['shipping_fee'] ?? $metadata['shipping_fee'] ?? 0);
        $totalHeld = (float) $wallet->amount;

        $feeSetting = FeeSetting::current();
        $sellerFeePercent = (float) $feeSetting->seller_fee_percent;

        $sellerFeeAmount = round($subtotal * ($sellerFeePercent / 100), 2);
        $sellerAmount = round($subtotal - $sellerFeeAmount, 2);
        $deliveryAmount = round($shippingFee, 2);
        $platformAmount = round($totalHeld - $sellerAmount - $deliveryAmount, 2);

        return [
            [
                'recipient_type' => 'seller',
                'recipient_id' => $walletMetadata['seller_id'] ?? $metadata['seller_id'] ?? null,
                'recipient_account' => $walletMetadata['seller_payout_account'] ?? $metadata['seller_payout_account'] ?? null,
                'amount' => $sellerAmount,
                'gross_amount' => round($subtotal, 2),
                'platform_fee' => $sellerFeeAmount,
                'net_amount' => $sellerAmount,
            ],
            [
                'recipient_type' => 'delivery_service',
                'recipient_id' => $walletMetadata['delivery_service_id'] ?? $metadata['delivery_service_id'] ?? null,
                'recipient_account' => $walletMetadata['delivery_payout_account'] ?? $metadata['delivery_payout_account'] ?? null,
                'amount' => $deliveryAmount,
                'gross_amount' => $deliveryAmount,
                'platform_fee' => 0.0,
                'net_amount' => $deliveryAmount,
            ],
            [
                'recipient_type' => 'platform',
                'recipient_id' => null,
                'recipient_account' => null,
                'amount' => $platformAmount,
                'gross_amount' => $platformAmount,
                'platform_fee' => 0.0,
                'net_amount' => $platformAmount,
            ],
        ];
    }

    private function calculateTrustDealSplits(EscrowWallet $wallet, array $metadata): array
    {
        $totalHeld = (float) $wallet->amount;
        $dealAmount = (float) ($metadata['subtotal'] ?? $metadata['deal_amount'] ?? $totalHeld);

        $feeSetting = FeeSetting::current();
        $sellerFeePercent = (float) $feeSetting->seller_fee_percent;

        $sellerFeeAmount = round($dealAmount * ($sellerFeePercent / 100), 2);
        $recipientAmount = round($dealAmount - $sellerFeeAmount, 2);
        $platformAmount = round(max(0, $totalHeld - $recipientAmount), 2);

        return [
            [
                'recipient_type' => $metadata['trust_recipient_type'] ?? 'user',
                'recipient_id' => $metadata['trust_seller_id'] ?? null,
                'recipient_account' => $metadata['trust_recipient_account'] ?? null,
                'amount' => $recipientAmount,
                'gross_amount' => round($dealAmount, 2),
                'platform_fee' => $sellerFeeAmount,
                'net_amount' => $recipientAmount,
            ],
            [
                'recipient_type' => 'platform',
                'recipient_id' => null,
                'recipient_account' => null,
                'amount' => $platformAmount,
                'gross_amount' => $platformAmount,
                'platform_fee' => 0.0,
                'net_amount' => $platformAmount,
            ],
        ];
    }

    private function metadataForWallet(EscrowWallet $wallet, array $metadata): array
    {
        $splits = $metadata['order_splits'] ?? [];

        if (! is_array($splits)) {
            return [];
        }

        foreach ($splits as $split) {
            if (! is_array($split)) {
                continue;
            }

            if (($split['order_reference'] ?? null) === $wallet->order_reference) {
                return $split;
            }
        }

        return [];
    }

    private function makePayoutReference(): string
    {
        do {
            $candidate = 'POUT' . now()->format('Ymd') . strtoupper(Str::random(6));
        } while (PayoutJob::where('reference', $candidate)->exists());

        return $candidate;
    }
}
