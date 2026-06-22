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
                    'currency' => $wallet->currency,
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

        $subtotal = (float) ($metadata['subtotal'] ?? 0);
        $shippingFee = (float) ($metadata['shipping_fee'] ?? 0);
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
                'recipient_id' => $metadata['seller_id'] ?? null,
                'recipient_account' => $metadata['seller_payout_account'] ?? null,
                'amount' => $sellerAmount,
            ],
            [
                'recipient_type' => 'delivery_service',
                'recipient_id' => $metadata['delivery_service_id'] ?? null,
                'recipient_account' => $metadata['delivery_payout_account'] ?? null,
                'amount' => $deliveryAmount,
            ],
            [
                'recipient_type' => 'platform',
                'recipient_id' => null,
                'recipient_account' => null,
                'amount' => $platformAmount,
            ],
        ];
    }

    private function makePayoutReference(): string
    {
        do {
            $candidate = 'POUT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (PayoutJob::where('reference', $candidate)->exists());

        return $candidate;
    }
}