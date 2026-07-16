<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayoutJob;
use App\Models\EscrowSplit;
use App\Models\PayoutJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function summary(string $ownerType, string $ownerId): JsonResponse
    {
        $this->validateOwnerType($ownerType);

        $splits = EscrowSplit::query()
            ->where('recipient_type', $ownerType)
            ->where('recipient_id', $ownerId)
            ->with(['escrowWallet:id,order_reference,status,held_at'])
            ->get();

        $currency = $splits->first()?->currency ?? 'TZS';

        $actual = $splits->sum(fn ($split) => (float) ($split->gross_amount ?: $split->amount));
        $pending = $splits
            ->where('status', 'held')
            ->sum(fn ($split) => (float) ($split->gross_amount ?: $split->amount));
        $available = $splits
            ->whereIn('status', ['available', 'failed'])
            ->sum(fn ($split) => (float) ($split->net_amount ?: $split->amount));
        $withdrawPending = $splits
            ->where('status', 'withdraw_pending')
            ->sum(fn ($split) => (float) ($split->net_amount ?: $split->amount));
        $withdrawn = $splits
            ->where('status', 'paid')
            ->sum(fn ($split) => (float) ($split->net_amount ?: $split->amount));
        $fees = $splits->sum(fn ($split) => (float) $split->platform_fee);

        return response()->json([
            'success' => true,
            'wallet' => [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'currency' => $currency,
                'actual_balance' => round($actual, 2),
                'pending_balance' => round($pending, 2),
                'available_balance' => round($available, 2),
                'withdraw_pending_balance' => round($withdrawPending, 2),
                'withdrawn_balance' => round($withdrawn, 2),
                'platform_fee_total' => round($fees, 2),
                'failed_payouts_count' => $splits->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function ledger(string $ownerType, string $ownerId): JsonResponse
    {
        $this->validateOwnerType($ownerType);

        $splits = EscrowSplit::query()
            ->where('recipient_type', $ownerType)
            ->where('recipient_id', $ownerId)
            ->with(['escrowWallet:id,order_reference,status,held_at', 'payoutJob'])
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'ledger' => $splits->through(fn (EscrowSplit $split) => [
                'id' => $split->id,
                'order_reference' => $split->escrowWallet?->order_reference,
                'status' => $split->status,
                'gross_amount' => (float) ($split->gross_amount ?: $split->amount),
                'platform_fee' => (float) $split->platform_fee,
                'net_amount' => (float) ($split->net_amount ?: $split->amount),
                'currency' => $split->currency,
                'available_at' => $split->available_at?->toDateTimeString(),
                'paid_at' => $split->paid_at?->toDateTimeString(),
                'payout' => $split->payoutJob ? [
                    'reference' => $split->payoutJob->reference,
                    'status' => $split->payoutJob->status,
                    'provider_key' => $split->payoutJob->provider_key,
                    'provider_reference' => $split->payoutJob->provider_reference,
                    'attempts' => $split->payoutJob->attempts,
                    'error_message' => $split->payoutJob->error_message,
                ] : null,
            ]),
        ]);
    }

    public function previewWithdrawal(Request $request): JsonResponse
    {
        $data = $this->validateWithdrawal($request);
        $available = $this->availableSplits($data['owner_type'], $data['owner_id'], false);
        $availableAmount = $available->sum(fn ($split) => (float) ($split->net_amount ?: $split->amount));
        $amount = round((float) ($data['amount'] ?? $availableAmount), 2);

        if ($amount <= 0 || $amount > $availableAmount) {
            throw ValidationException::withMessages([
                'amount' => 'Withdrawal amount exceeds available balance.',
            ]);
        }

        return response()->json([
            'success' => true,
            'preview' => [
                'owner_type' => $data['owner_type'],
                'owner_id' => $data['owner_id'],
                'amount' => $amount,
                'currency' => $available->first()?->currency ?? 'TZS',
                'provider_key' => $data['provider_key'],
                'payout_account' => $data['payout_account'],
                'splits_count' => $this->countSplitsForAmount($available, $amount),
            ],
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $data = $this->validateWithdrawal($request);
        return DB::transaction(function () use ($data) {
            $available = $this->availableSplits($data['owner_type'], $data['owner_id']);
            $availableAmount = $available->sum(fn ($split) => (float) ($split->net_amount ?: $split->amount));
            $amount = round((float) ($data['amount'] ?? $availableAmount), 2);

            if ($amount <= 0 || $amount > $availableAmount) {
                throw ValidationException::withMessages([
                    'amount' => 'Withdrawal amount exceeds available balance.',
                ]);
            }

            $selected = $this->selectSplits($available, $amount);
            $jobs = [];

            foreach ($selected as $split) {
                $split->update([
                    'status' => 'withdraw_pending',
                    'recipient_account' => $this->normalizeAccount($data['payout_account']),
                ]);

                $job = PayoutJob::create([
                    'reference' => $this->makePayoutReference(),
                    'escrow_split_id' => $split->id,
                    'recipient_type' => $split->recipient_type,
                    'recipient_id' => $split->recipient_id,
                    'amount' => $split->net_amount ?: $split->amount,
                    'currency' => $split->currency,
                    'provider_key' => $data['provider_key'],
                    'status' => 'queued',
                ]);

                $split->update(['payout_job_id' => $job->id]);

                ProcessPayoutJob::dispatch($job->id)->onQueue('payments');
                $jobs[] = $job;
            }

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal queued.',
                'withdrawals' => collect($jobs)->map(fn (PayoutJob $job) => [
                    'reference' => $job->reference,
                    'status' => $job->status,
                    'amount' => (float) $job->amount,
                    'currency' => $job->currency,
                    'provider_key' => $job->provider_key,
                ])->values(),
            ]);
        });
    }

    private function validateWithdrawal(Request $request): array
    {
        $data = $request->validate([
            'owner_type' => ['required', 'in:seller,delivery_service,user'],
            'owner_id' => ['required', 'string', 'max:80'],
            'amount' => ['nullable', 'numeric', 'min:1'],
            'provider_key' => ['required', 'string', 'max:64'],
            'payout_account' => ['required', 'array'],
            'payout_account.method' => ['required', 'in:mobile,bank,wallet'],
            'payout_account.phone' => ['nullable', 'string', 'max:30'],
            'payout_account.mobile_phone' => ['nullable', 'string', 'max:30'],
            'payout_account.mobile_network' => ['nullable', 'string', 'max:80'],
            'payout_account.account_name' => ['nullable', 'string', 'max:120'],
            'payout_account.account_number' => ['nullable', 'string', 'max:120'],
            'payout_account.bank_name' => ['nullable', 'string', 'max:120'],
        ]);

        $this->validateOwnerType($data['owner_type']);

        return $data;
    }

    private function availableSplits(string $ownerType, string $ownerId, bool $lock = true)
    {
        $query = EscrowSplit::query()
            ->where('recipient_type', $ownerType)
            ->where('recipient_id', $ownerId)
            ->whereIn('status', ['available', 'failed'])
            ->orderBy('available_at')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function selectSplits($splits, float $amount)
    {
        $remaining = round($amount, 2);
        $selected = collect();

        foreach ($splits as $split) {
            if ($remaining <= 0) {
                break;
            }

            $splitAmount = round((float) ($split->net_amount ?: $split->amount), 2);

            if ($splitAmount <= $remaining + 0.00001) {
                $selected->push($split);
                $remaining = round($remaining - $splitAmount, 2);
                continue;
            }

            $selected->push($this->splitForPartialWithdrawal($split, $remaining));
            $remaining = 0;
        }

        return $selected;
    }

    private function countSplitsForAmount($splits, float $amount): int
    {
        $remaining = round($amount, 2);
        $count = 0;

        foreach ($splits as $split) {
            if ($remaining <= 0) {
                break;
            }

            $splitAmount = round((float) ($split->net_amount ?: $split->amount), 2);
            $remaining = round($remaining - min($remaining, $splitAmount), 2);
            $count++;
        }

        return $count;
    }

    private function splitForPartialWithdrawal(EscrowSplit $split, float $amount): EscrowSplit
    {
        $currentNet = round((float) ($split->net_amount ?: $split->amount), 2);
        $leftoverNet = round($currentNet - $amount, 2);

        if ($leftoverNet <= 0) {
            return $split;
        }

        $ratio = $currentNet > 0 ? $amount / $currentNet : 1;
        $withdrawGross = round((float) ($split->gross_amount ?: $split->amount) * $ratio, 2);
        $withdrawFee = round((float) $split->platform_fee * $ratio, 2);

        $leftover = $split->replicate([
            'amount',
            'gross_amount',
            'platform_fee',
            'net_amount',
            'payout_job_id',
            'paid_at',
        ]);
        $leftover->amount = $leftoverNet;
        $leftover->gross_amount = round((float) ($split->gross_amount ?: $split->amount) - $withdrawGross, 2);
        $leftover->platform_fee = round((float) $split->platform_fee - $withdrawFee, 2);
        $leftover->net_amount = $leftoverNet;
        $leftover->status = 'available';
        $leftover->payout_job_id = null;
        $leftover->paid_at = null;
        $leftover->save();

        $split->update([
            'amount' => $amount,
            'gross_amount' => $withdrawGross,
            'platform_fee' => $withdrawFee,
            'net_amount' => $amount,
        ]);

        return $split->fresh();
    }

    private function normalizeAccount(array $account): array
    {
        if (($account['method'] ?? null) === 'mobile' && empty($account['phone'])) {
            $account['phone'] = $account['mobile_phone'] ?? null;
        }

        return $account;
    }

    private function validateOwnerType(string $ownerType): void
    {
        if (! in_array($ownerType, ['seller', 'delivery_service', 'user'], true)) {
            throw ValidationException::withMessages([
                'owner_type' => 'Invalid wallet owner type.',
            ]);
        }
    }

    private function makePayoutReference(): string
    {
        do {
            $candidate = 'POUT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (PayoutJob::where('reference', $candidate)->exists());

        return $candidate;
    }
}
