<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EscrowWallet;
use App\Models\PaymentTransaction;
use App\Models\PayoutJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RickConnectorController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) {
            return $denied;
        }

        $checks = [
            'database' => $this->checkDatabase(),
            'payment_transactions_table' => Schema::hasTable('payment_transactions') ? 'healthy' : 'down',
            'escrow_wallets_table' => Schema::hasTable('escrow_wallets') ? 'healthy' : 'down',
            'payout_jobs_table' => Schema::hasTable('payout_jobs') ? 'healthy' : 'down',
        ];

        return response()->json([
            'service' => 'payment-service',
            'status' => in_array('down', $checks, true) ? 'degraded' : 'healthy',
            'generated_at' => now()->toIso8601String(),
            'checks' => $checks,
        ]);
    }

    public function query(Request $request): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) {
            return $denied;
        }

        $search = $this->searchPayload($request);
        $transactions = $this->searchTransactions($search);
        $escrowWallets = $this->searchEscrowWallets($search, $transactions->pluck('id')->all());
        $payouts = $this->searchPayouts($search);

        return response()->json([
            'service' => 'payment-service',
            'status' => 'ok',
            'summary' => sprintf(
                'Found %d payment transactions, %d escrow wallets, and %d payout jobs.',
                $transactions->count(),
                $escrowWallets->count(),
                $payouts->count()
            ),
            'facts' => [
                'matches' => [
                    'transactions' => $transactions,
                    'escrow_wallets' => $escrowWallets,
                    'payouts' => $payouts,
                ],
                'activity_counts' => [
                    'transactions' => $transactions->count(),
                    'escrow_wallets' => $escrowWallets->count(),
                    'payouts' => $payouts->count(),
                ],
            ],
            'records' => [],
        ]);
    }

    private function searchTransactions(array $search)
    {
        if (! Schema::hasTable('payment_transactions')) {
            return collect();
        }

        return PaymentTransaction::query()
            ->where(function ($query) use ($search) {
                foreach ($search['ids'] as $id) {
                    $query->orWhere('id', $id);
                }
                foreach ($search['references'] as $reference) {
                    $query->orWhere('reference', 'like', '%'.$reference.'%')
                        ->orWhere('order_reference', 'like', '%'.$reference.'%')
                        ->orWhere('provider_reference', 'like', '%'.$reference.'%');
                }
                foreach ($search['phones'] as $phone) {
                    $query->orWhere('phone', 'like', '%'.ltrim($phone, '+').'%');
                }
                foreach ([...$search['names'], ...$search['keywords']] as $term) {
                    $query->orWhere('payer_name', 'like', '%'.$term.'%')
                        ->orWhere('metadata', 'like', '%'.$term.'%');
                }
            })
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (PaymentTransaction $transaction) => [
                'type' => 'payment_transaction',
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'order_reference' => $transaction->order_reference,
                'provider_reference' => $transaction->provider_reference,
                'payer_name' => $transaction->payer_name,
                'phone' => $transaction->phone,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'confirmed_at' => optional($transaction->confirmed_at)->toDateTimeString(),
                'failed_at' => optional($transaction->failed_at)->toDateTimeString(),
                'created_at' => optional($transaction->created_at)->toDateTimeString(),
            ]);
    }

    private function searchEscrowWallets(array $search, array $transactionIds)
    {
        if (! Schema::hasTable('escrow_wallets')) {
            return collect();
        }

        return EscrowWallet::query()
            ->where(function ($query) use ($search, $transactionIds) {
                if ($transactionIds !== []) {
                    $query->orWhereIn('transaction_id', $transactionIds);
                }
                foreach ($search['references'] as $reference) {
                    $query->orWhere('order_reference', 'like', '%'.$reference.'%');
                }
            })
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (EscrowWallet $wallet) => [
                'type' => 'escrow_wallet',
                'id' => $wallet->id,
                'transaction_id' => $wallet->transaction_id,
                'order_reference' => $wallet->order_reference,
                'amount' => (float) $wallet->amount,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
                'held_at' => optional($wallet->held_at)->toDateTimeString(),
                'released_at' => optional($wallet->released_at)->toDateTimeString(),
            ]);
    }

    private function searchPayouts(array $search)
    {
        if (! Schema::hasTable('payout_jobs')) {
            return collect();
        }

        return PayoutJob::query()
            ->where(function ($query) use ($search) {
                foreach ($search['ids'] as $id) {
                    $query->orWhere('recipient_id', $id);
                }
                foreach ($search['references'] as $reference) {
                    $query->orWhere('reference', 'like', '%'.$reference.'%')
                        ->orWhere('provider_reference', 'like', '%'.$reference.'%');
                }
            })
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (PayoutJob $payout) => [
                'type' => 'payout_job',
                'id' => $payout->id,
                'reference' => $payout->reference,
                'recipient_type' => $payout->recipient_type,
                'recipient_id' => $payout->recipient_id,
                'amount' => (float) $payout->amount,
                'currency' => $payout->currency,
                'status' => $payout->status,
                'provider_reference' => $payout->provider_reference,
                'attempts' => $payout->attempts,
                'completed_at' => optional($payout->completed_at)->toDateTimeString(),
            ]);
    }

    private function searchPayload(Request $request): array
    {
        $search = $request->input('search', $request->input('intent.search', []));

        return [
            'text' => (string) data_get($search, 'text', ''),
            'emails' => array_values((array) data_get($search, 'emails', [])),
            'phones' => array_values((array) data_get($search, 'phones', [])),
            'ids' => array_values((array) data_get($search, 'ids', [])),
            'names' => array_values((array) data_get($search, 'names', [])),
            'references' => array_values((array) data_get($search, 'references', [])),
            'keywords' => array_values((array) data_get($search, 'keywords', [])),
        ];
    }

    private function denyIfUnauthorized(Request $request): ?JsonResponse
    {
        $expected = (string) config('services.rick.token');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized Rick connector request.'], 401);
        }

        return null;
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('select 1');
            return 'healthy';
        } catch (\Throwable) {
            return 'down';
        }
    }
}