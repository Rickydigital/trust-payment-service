<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayoutJob;
use App\Jobs\ProcessRefundRequestJob;
use App\Models\EscrowSplit;
use App\Models\EscrowWallet;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PayoutJob;
use App\Models\RefundRequest;
use App\Models\WebhookLog;
use App\Services\PaymentRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TomsPaymentOperationsController extends Controller
{
    public function __construct(
        private readonly PaymentRouter $router,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $transactions = PaymentTransaction::query();
        $payouts = PayoutJob::query();
        $escrow = EscrowWallet::query();
        $webhooks = WebhookLog::query();
        $refunds = RefundRequest::query();

        return response()->json([
            'data' => [
                'metrics' => [
                    ['label' => 'Collected Today', 'value' => (float) (clone $transactions)->where('status', 'confirmed')->where('confirmed_at', '>=', $today)->sum('amount'), 'currency' => 'TZS', 'icon' => 'mdi-cash-check'],
                    ['label' => 'Collected This Month', 'value' => (float) (clone $transactions)->where('status', 'confirmed')->where('confirmed_at', '>=', $month)->sum('amount'), 'currency' => 'TZS', 'icon' => 'mdi-chart-line'],
                    ['label' => 'Pending Payments', 'value' => (clone $transactions)->whereIn('status', ['initiated', 'pending'])->count(), 'icon' => 'mdi-timer-sand'],
                    ['label' => 'Failed Payments', 'value' => (clone $transactions)->where('status', 'failed')->count(), 'icon' => 'mdi-alert-circle-outline'],
                    ['label' => 'Escrow Holding', 'value' => (float) (clone $escrow)->where('status', 'holding')->sum('amount'), 'currency' => 'TZS', 'icon' => 'mdi-lock-outline'],
                    ['label' => 'Pending Payouts', 'value' => (clone $payouts)->whereIn('status', ['pending', 'processing', 'manual_review'])->count(), 'icon' => 'mdi-bank-transfer-out'],
                    ['label' => 'Failed Payouts', 'value' => (clone $payouts)->where('status', 'failed')->count(), 'icon' => 'mdi-cash-remove'],
                    ['label' => 'Refund Requests', 'value' => (clone $refunds)->whereIn('status', ['requested', 'approved', 'processing', 'manual_review'])->count(), 'icon' => 'mdi-cash-refund'],
                    ['label' => 'Webhook Issues', 'value' => (clone $webhooks)->where(fn ($query) => $query->where('signature_valid', false)->orWhere('processing_status', 'failed'))->count(), 'icon' => 'mdi-webhook'],
                ],
                'provider_status' => PaymentMethod::query()
                    ->with('provider')
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (PaymentMethod $method) => $this->providerSummary($method))
                    ->values(),
                'charts' => [
                    'transactions_by_status' => $this->statusBreakdown(PaymentTransaction::query(), 'status'),
                    'payouts_by_status' => $this->statusBreakdown(PayoutJob::query(), 'status'),
                    'refunds_by_status' => $this->statusBreakdown(RefundRequest::query(), 'status'),
                    'escrow_by_status' => $this->statusBreakdown(EscrowWallet::query(), 'status'),
                    'webhooks_by_status' => $this->statusBreakdown(WebhookLog::query(), 'processing_status'),
                ],
                'recent' => [
                    'transactions' => PaymentTransaction::query()
                        ->with('paymentMethod')
                        ->latest()
                        ->limit(8)
                        ->get()
                        ->map(fn (PaymentTransaction $transaction) => $this->transactionRow($transaction))
                        ->values(),
                    'payouts' => PayoutJob::query()
                        ->latest()
                        ->limit(8)
                        ->get()
                        ->map(fn (PayoutJob $payout) => $this->payoutRow($payout))
                        ->values(),
                    'refunds' => RefundRequest::query()
                        ->with('transaction.paymentMethod')
                        ->latest()
                        ->limit(8)
                        ->get()
                        ->map(fn (RefundRequest $refund) => $this->refundRow($refund))
                        ->values(),
                    'webhooks' => WebhookLog::query()
                        ->latest('created_at')
                        ->limit(8)
                        ->get()
                        ->map(fn (WebhookLog $webhook) => $this->webhookRow($webhook))
                        ->values(),
                ],
            ],
        ]);
    }

    public function providers(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $methods = PaymentMethod::query()
            ->with('provider')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->query('q') . '%';
                $query->where(fn ($query) => $query
                    ->where('provider_key', 'like', $q)
                    ->orWhere('display_name', 'like', $q)
                    ->orWhere('type', 'like', $q));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->query('status') === 'active'))
            ->orderBy('sort_order')
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return response()->json(['data' => $this->paginate($methods, fn (PaymentMethod $method) => $this->providerSummary($method))]);
    }

    public function provider(Request $request, string $provider): JsonResponse
    {
        $this->authorizeInternal($request);

        $method = $this->methodByProvider($provider);

        return response()->json([
            'data' => [
                'provider' => $this->providerDetail($method),
                'transactions' => PaymentTransaction::query()
                    ->with('paymentMethod')
                    ->where('payment_method_id', $method->id)
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(fn (PaymentTransaction $transaction) => $this->transactionRow($transaction))
                    ->values(),
                'attempts' => PaymentAttempt::query()
                    ->where('provider_key', $method->provider_key)
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(fn (PaymentAttempt $attempt) => $this->attemptRow($attempt))
                    ->values(),
                'payouts' => PayoutJob::query()
                    ->where('provider_key', $method->provider_key)
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(fn (PayoutJob $payout) => $this->payoutRow($payout))
                    ->values(),
                'webhooks' => WebhookLog::query()
                    ->where('provider', $method->provider_key)
                    ->latest('created_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (WebhookLog $webhook) => $this->webhookRow($webhook))
                    ->values(),
            ],
        ]);
    }

    public function providerHealth(Request $request, string $provider): JsonResponse
    {
        $this->authorizeInternal($request);

        $method = $this->methodByProvider($provider);
        $health = $this->driverHealth($method);

        $method->provider?->update([
            'health_status' => $health['status'] ?? 'unknown',
            'last_checked_at' => now(),
            'last_success_at' => (($health['driver_exists'] ?? false) && ($health['driver_valid'] ?? false)) ? now() : null,
            'last_failure_at' => (($health['driver_exists'] ?? false) && ($health['driver_valid'] ?? false)) ? null : now(),
            'last_error' => (($health['driver_exists'] ?? false) && ($health['driver_valid'] ?? false)) ? null : ($health['message'] ?? null),
        ]);

        return response()->json([
            'data' => [
                'provider' => $method->provider_key,
                'health' => $health,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function providerEnable(Request $request, string $provider): JsonResponse
    {
        $this->authorizeInternal($request);

        $method = $this->methodByProvider($provider);
        $health = $this->driverHealth($method);

        if (! ($health['driver_exists'] ?? false) || ! ($health['driver_valid'] ?? false)) {
            return response()->json([
                'message' => 'Provider cannot be enabled until its driver exists and implements the payment contract.',
                'data' => ['health' => $health],
            ], 422);
        }

        $method->provider?->update([
            'is_active' => true,
            'status' => 'active',
            'health_status' => $health['status'] ?? 'unknown',
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
        ]);
        $method->update(['is_active' => true]);
        $this->router->forget($method->provider_key);

        return response()->json(['data' => ['provider' => $this->providerSummary($method->fresh())]]);
    }

    public function providerDisable(Request $request, string $provider): JsonResponse
    {
        $this->authorizeInternal($request);

        $method = $this->methodByProvider($provider);
        $method->provider?->update([
            'is_active' => false,
            'status' => 'disabled',
            'last_checked_at' => now(),
        ]);
        $method->update(['is_active' => false]);
        $this->router->forget($method->provider_key);

        return response()->json(['data' => ['provider' => $this->providerSummary($method->fresh())]]);
    }

    public function methods(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $methods = PaymentMethod::query()
            ->with('provider')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->query('q') . '%';
                $query->where(fn ($query) => $query
                    ->where('provider_key', 'like', $q)
                    ->orWhere('display_name', 'like', $q)
                    ->orWhere('type', 'like', $q));
            })
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->query('status') === 'active'))
            ->orderBy('sort_order')
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return response()->json(['data' => $this->paginate($methods, fn (PaymentMethod $method) => $this->methodRow($method))]);
    }

    public function methodEnable(Request $request, PaymentMethod $method): JsonResponse
    {
        $this->authorizeInternal($request);

        $health = $this->driverHealth($method);

        if (! ($health['driver_exists'] ?? false) || ! ($health['driver_valid'] ?? false)) {
            return response()->json([
                'message' => 'Payment method cannot be enabled until its driver is ready.',
                'data' => ['health' => $health],
            ], 422);
        }

        $method->update(['is_active' => true]);
        $this->router->forget($method->provider_key);

        return response()->json(['data' => ['method' => $this->methodRow($method->fresh())]]);
    }

    public function methodDisable(Request $request, PaymentMethod $method): JsonResponse
    {
        $this->authorizeInternal($request);

        $method->update(['is_active' => false]);
        $this->router->forget($method->provider_key);

        return response()->json(['data' => ['method' => $this->methodRow($method->fresh())]]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $transactions = PaymentTransaction::query()
            ->with('paymentMethod')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('provider_key'), fn ($query) => $query->whereHas('paymentMethod', fn ($methodQuery) => $methodQuery->where('provider_key', $request->query('provider_key'))))
            ->when($request->filled('context'), fn ($query) => $query->where('metadata->payment_context', $request->query('context')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->query('q') . '%';
                $query->where(fn ($query) => $query
                    ->where('reference', 'like', $q)
                    ->orWhere('order_reference', 'like', $q)
                    ->orWhere('provider_reference', 'like', $q)
                    ->orWhere('phone', 'like', $q)
                    ->orWhere('payer_name', 'like', $q));
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return response()->json(['data' => $this->paginate($transactions, fn (PaymentTransaction $transaction) => $this->transactionRow($transaction))]);
    }

    public function transaction(Request $request, string $reference): JsonResponse
    {
        $this->authorizeInternal($request);

        $transaction = PaymentTransaction::query()
            ->with(['paymentMethod', 'escrowWallets.splits.payoutJob'])
            ->where('reference', $reference)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'transaction' => $this->transactionDetail($transaction),
                'attempts' => PaymentAttempt::query()
                    ->where('payment_transaction_id', $transaction->id)
                    ->latest()
                    ->get()
                    ->map(fn (PaymentAttempt $attempt) => $this->attemptRow($attempt))
                    ->values(),
                'webhooks' => WebhookLog::query()
                    ->where('matched_transaction_id', $transaction->id)
                    ->latest('created_at')
                    ->get()
                    ->map(fn (WebhookLog $webhook) => $this->webhookRow($webhook))
                    ->values(),
            ],
        ]);
    }

    public function syncTransactionStatus(Request $request, string $reference): JsonResponse
    {
        $this->authorizeInternal($request);

        $transaction = PaymentTransaction::query()
            ->with('paymentMethod')
            ->where('reference', $reference)
            ->firstOrFail();

        if (! $transaction->provider_reference) {
            return response()->json(['message' => 'Transaction has no provider reference to query.'], 422);
        }

        $driver = $this->router->driverFor($transaction->paymentMethod);
        $result = $driver->queryStatus($transaction->provider_reference);
        $status = $result['status'] ?? $transaction->status;

        $transaction->update([
            'status' => $status,
            'confirmed_at' => $status === 'confirmed' ? ($transaction->confirmed_at ?: now()) : $transaction->confirmed_at,
            'failed_at' => $status === 'failed' ? ($transaction->failed_at ?: now()) : $transaction->failed_at,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'last_toms_status_sync' => [
                    'at' => now()->toIso8601String(),
                    'provider_response' => Arr::except($result, ['raw']),
                ],
            ]),
        ]);

        PaymentAttempt::query()
            ->where('payment_transaction_id', $transaction->id)
            ->latest()
            ->limit(1)
            ->update([
                'status' => $status,
                'confirmed_at' => $status === 'confirmed' ? now() : null,
                'failed_at' => $status === 'failed' ? now() : null,
                'failure_reason' => $status === 'failed' ? ($result['message'] ?? 'Status sync marked failed.') : null,
            ]);

        return response()->json([
            'data' => [
                'transaction' => $this->transactionDetail($transaction->fresh('paymentMethod')),
                'provider_response' => Arr::except($result, ['raw']),
            ],
        ]);
    }

    public function escrow(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $wallets = EscrowWallet::query()
            ->with(['transaction.paymentMethod', 'splits.payoutJob'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->query('q') . '%';
                $query->where(fn ($query) => $query
                    ->where('order_reference', 'like', $q)
                    ->orWhereHas('transaction', fn ($transactionQuery) => $transactionQuery->where('reference', 'like', $q)));
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return response()->json([
            'data' => array_merge($this->paginate($wallets, fn (EscrowWallet $wallet) => $this->escrowRow($wallet)), [
                'summary' => [
                    'holding' => (float) EscrowWallet::query()->where('status', 'holding')->sum('amount'),
                    'released' => (float) EscrowWallet::query()->where('status', 'released')->sum('amount'),
                    'refunded' => (float) EscrowWallet::query()->where('status', 'refunded')->sum('amount'),
                    'available_splits' => (float) EscrowSplit::query()->whereIn('status', ['available', 'released'])->sum('net_amount'),
                ],
            ]),
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $payouts = PayoutJob::query()
            ->with('escrowSplit.escrowWallet')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('provider_key'), fn ($query) => $query->where('provider_key', $request->query('provider_key')))
            ->when($request->filled('recipient_type'), fn ($query) => $query->where('recipient_type', $request->query('recipient_type')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->query('q') . '%';
                $query->where(fn ($query) => $query
                    ->where('reference', 'like', $q)
                    ->orWhere('recipient_id', 'like', $q)
                    ->orWhere('provider_reference', 'like', $q)
                    ->orWhere('error_message', 'like', $q));
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return response()->json(['data' => $this->paginate($payouts, fn (PayoutJob $payout) => $this->payoutRow($payout))]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $refunds = RefundRequest::query()
            ->with('transaction.paymentMethod')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('provider_key'), fn ($query) => $query->where('provider_key', $request->query('provider_key')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->query('q') . '%';
                $query->where(fn ($query) => $query
                    ->where('reference', 'like', $q)
                    ->orWhere('order_reference', 'like', $q)
                    ->orWhere('return_reference', 'like', $q)
                    ->orWhere('dispute_reference', 'like', $q)
                    ->orWhere('provider_reference', 'like', $q)
                    ->orWhere('reason', 'like', $q));
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return response()->json(['data' => $this->paginate($refunds, fn (RefundRequest $refund) => $this->refundRow($refund))]);
    }

    public function approveRefund(Request $request, string $reference): JsonResponse
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'actor' => ['nullable', 'string', 'max:255'],
        ]);

        $refund = RefundRequest::query()->where('reference', $reference)->firstOrFail();

        if (! in_array($refund->status, ['requested', 'failed', 'manual_review'], true)) {
            return response()->json(['message' => 'Only requested, failed, or manual review refunds can be approved.'], 422);
        }

        $refund->update([
            'status' => 'approved',
            'review_note' => $data['reason'],
            'approved_by' => $data['actor'] ?? null,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'failure_reason' => null,
        ]);

        ProcessRefundRequestJob::dispatch($refund->id)->onQueue('payments');

        return response()->json(['data' => ['refund' => $this->refundRow($refund->fresh('transaction.paymentMethod'))]]);
    }

    public function rejectRefund(Request $request, string $reference): JsonResponse
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'actor' => ['nullable', 'string', 'max:255'],
        ]);

        $refund = RefundRequest::query()->where('reference', $reference)->firstOrFail();

        if (! in_array($refund->status, ['requested', 'manual_review', 'failed'], true)) {
            return response()->json(['message' => 'This refund cannot be rejected in its current status.'], 422);
        }

        $refund->update([
            'status' => 'rejected',
            'review_note' => $data['reason'],
            'rejected_by' => $data['actor'] ?? null,
            'rejected_at' => now(),
        ]);

        $this->sendRefundCallback($refund->fresh('transaction.paymentMethod'));

        return response()->json(['data' => ['refund' => $this->refundRow($refund->fresh('transaction.paymentMethod'))]]);
    }

    public function retryRefund(Request $request, string $reference): JsonResponse
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'actor' => ['nullable', 'string', 'max:255'],
        ]);

        $refund = RefundRequest::query()->where('reference', $reference)->firstOrFail();

        if (! in_array($refund->status, ['failed', 'manual_review', 'approved'], true)) {
            return response()->json(['message' => 'Only failed, manual review, or approved refunds can be retried.'], 422);
        }

        $refund->update([
            'status' => 'approved',
            'review_note' => trim(($refund->review_note ? $refund->review_note . "\n" : '') . 'Retry: ' . $data['reason']),
            'approved_by' => $data['actor'] ?? $refund->approved_by,
            'approved_at' => $refund->approved_at ?: now(),
            'failure_reason' => null,
        ]);

        ProcessRefundRequestJob::dispatch($refund->id)->onQueue('payments');

        return response()->json(['data' => ['refund' => $this->refundRow($refund->fresh('transaction.paymentMethod'))]]);
    }

    public function completeRefund(Request $request, string $reference): JsonResponse
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'provider_reference' => ['nullable', 'string', 'max:120'],
            'actor' => ['nullable', 'string', 'max:255'],
        ]);

        $refund = RefundRequest::query()->where('reference', $reference)->firstOrFail();

        if (! in_array($refund->status, ['approved', 'processing', 'failed', 'manual_review'], true)) {
            return response()->json(['message' => 'Only approved, processing, failed, or manual review refunds can be completed.'], 422);
        }

        $refund->update([
            'status' => 'completed',
            'review_note' => trim(($refund->review_note ? $refund->review_note . "\n" : '') . 'Completed: ' . $data['reason']),
            'provider_reference' => $data['provider_reference'] ?? $refund->provider_reference,
            'provider_response' => array_merge($refund->provider_response ?? [], [
                'manual_completion' => true,
                'completed_by' => $data['actor'] ?? null,
                'completed_reason' => $data['reason'],
                'completed_at' => now()->toDateTimeString(),
            ]),
            'failure_reason' => null,
            'completed_at' => now(),
        ]);

        $this->sendRefundCallback($refund->fresh('transaction.paymentMethod'));

        return response()->json(['data' => ['refund' => $this->refundRow($refund->fresh('transaction.paymentMethod'))]]);
    }

    public function retryPayout(Request $request, string $reference): JsonResponse
    {
        $this->authorizeInternal($request);

        $payout = PayoutJob::query()->where('reference', $reference)->firstOrFail();

        if (! in_array($payout->status, ['failed', 'pending', 'manual_review'], true)) {
            return response()->json(['message' => 'Only failed, pending, or manual review payouts can be retried.'], 422);
        }

        $payout->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        ProcessPayoutJob::dispatch($payout->id)->onQueue('payments');

        return response()->json(['data' => ['payout' => $this->payoutRow($payout->fresh())]]);
    }

    public function webhooks(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $webhooks = WebhookLog::query()
            ->with('matchedTransaction.paymentMethod')
            ->when($request->filled('provider'), fn ($query) => $query->where('provider', $request->query('provider')))
            ->when($request->filled('status'), fn ($query) => $query->where('processing_status', $request->query('status')))
            ->when($request->filled('signature'), fn ($query) => $query->where('signature_valid', $request->query('signature') === 'valid'))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->query('q') . '%';
                $query->where(fn ($query) => $query
                    ->where('provider', 'like', $q)
                    ->orWhere('endpoint', 'like', $q)
                    ->orWhere('error_message', 'like', $q));
            })
            ->latest('created_at')
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return response()->json(['data' => $this->paginate($webhooks, fn (WebhookLog $webhook) => $this->webhookRow($webhook))]);
    }

    public function reviewWebhook(Request $request, WebhookLog $webhook): JsonResponse
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'resolution' => ['required', 'string', 'max:1000'],
            'status' => ['nullable', 'in:processed,ignored,failed'],
        ]);

        $webhook->update([
            'processing_status' => $data['status'] ?? ($webhook->processing_status === 'failed' ? 'ignored' : $webhook->processing_status),
            'error_message' => trim(($webhook->error_message ? $webhook->error_message . "\n" : '') . 'TOMS review: ' . $data['resolution']),
            'processed_at' => $webhook->processed_at ?: now(),
        ]);

        return response()->json(['data' => ['webhook' => $this->webhookRow($webhook->fresh())]]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $stalePending = PaymentTransaction::query()
            ->with('paymentMethod')
            ->whereIn('status', ['initiated', 'pending'])
            ->where('created_at', '<=', now()->subMinutes(15))
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (PaymentTransaction $transaction) => $this->reconciliationItem('stale_pending_payment', 'Stale pending payment', 'Payment has been pending for more than 15 minutes.', $transaction->status, $this->transactionRow($transaction)));

        $missingReference = PaymentTransaction::query()
            ->with('paymentMethod')
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereNull('provider_reference')
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (PaymentTransaction $transaction) => $this->reconciliationItem('missing_provider_reference', 'Missing provider reference', 'Payment has no provider reference.', $transaction->status, $this->transactionRow($transaction)));

        $failedWebhooks = WebhookLog::query()
            ->where(fn ($query) => $query->where('signature_valid', false)->orWhere('processing_status', 'failed'))
            ->latest('created_at')
            ->limit(25)
            ->get()
            ->map(fn (WebhookLog $webhook) => $this->reconciliationItem('webhook_issue', 'Webhook issue', $webhook->error_message ?: 'Webhook failed validation or processing.', $webhook->processing_status, $this->webhookRow($webhook)));

        $failedPayouts = PayoutJob::query()
            ->where('status', 'failed')
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (PayoutJob $payout) => $this->reconciliationItem('failed_payout', 'Failed payout', $payout->error_message ?: 'Payout failed and needs review.', $payout->status, $this->payoutRow($payout)));

        $refundIssues = RefundRequest::query()
            ->whereIn('status', ['failed', 'manual_review'])
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (RefundRequest $refund) => $this->reconciliationItem('refund_issue', 'Refund issue', $refund->failure_reason ?: 'Refund needs manual review.', $refund->status, $this->refundRow($refund)));

        $items = $stalePending
            ->merge($missingReference)
            ->merge($failedWebhooks)
            ->merge($failedPayouts)
            ->merge($refundIssues)
            ->sortByDesc('occurred_at')
            ->values();

        return response()->json([
            'data' => [
                'items' => $items,
                'summary' => [
                    'stale_pending_payments' => $stalePending->count(),
                    'missing_provider_references' => $missingReference->count(),
                    'webhook_issues' => $failedWebhooks->count(),
                    'failed_payouts' => $failedPayouts->count(),
                    'refund_issues' => $refundIssues->count(),
                    'total_issues' => $items->count(),
                ],
            ],
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $days = max(1, min((int) $request->query('days', 14), 60));
        $from = now()->subDays($days - 1)->startOfDay();

        $transactions = PaymentTransaction::query()
            ->with('paymentMethod')
            ->where('created_at', '>=', $from)
            ->get();
        $payouts = PayoutJob::query()
            ->where('created_at', '>=', $from)
            ->get();
        $refunds = RefundRequest::query()
            ->where('created_at', '>=', $from)
            ->get();

        return response()->json([
            'data' => [
                'days' => $days,
                'daily_collections' => $transactions
                    ->where('status', 'confirmed')
                    ->groupBy(fn (PaymentTransaction $transaction) => $transaction->created_at?->toDateString() ?: 'unknown')
                    ->map(fn ($rows, $day) => [
                        'label' => $day,
                        'count' => $rows->count(),
                        'amount' => (float) $rows->sum('amount'),
                    ])
                    ->values(),
                'provider_performance' => $transactions
                    ->groupBy(fn (PaymentTransaction $transaction) => $transaction->paymentMethod?->provider_key ?: 'unknown')
                    ->map(fn ($rows, $provider) => [
                        'label' => $provider,
                        'total' => $rows->count(),
                        'confirmed' => $rows->where('status', 'confirmed')->count(),
                        'failed' => $rows->where('status', 'failed')->count(),
                        'amount' => (float) $rows->where('status', 'confirmed')->sum('amount'),
                    ])
                    ->values(),
                'payouts' => $payouts
                    ->groupBy('status')
                    ->map(fn ($rows, $status) => [
                        'label' => $status,
                        'count' => $rows->count(),
                        'amount' => (float) $rows->sum('amount'),
                    ])
                    ->values(),
                'refunds' => $refunds
                    ->groupBy('status')
                    ->map(fn ($rows, $status) => [
                        'label' => $status,
                        'count' => $rows->count(),
                        'amount' => (float) $rows->sum('amount'),
                    ])
                    ->values(),
            ],
        ]);
    }

    private function providerSummary(PaymentMethod $method): array
    {
        $health = $this->driverHealth($method);

        return [
            'key' => $method->provider_key,
            'name' => $method->display_name,
            'type' => $method->type,
            'status' => ($method->provider?->is_active ?? $method->is_active) && $method->is_active ? 'active' : 'disabled',
            'is_active' => ($method->provider?->is_active ?? $method->is_active) && $method->is_active,
            'logo_url' => $method->logo_url,
            'sort_order' => $method->sort_order,
            'provider_record_id' => $method->provider?->id,
            'provider_status' => $method->provider?->status,
            'driver' => class_basename($method->driver_class),
            'driver_class' => $method->driver_class,
            'driver_exists' => $health['driver_exists'],
            'driver_valid' => $health['driver_valid'],
            'health_status' => $health['status'],
            'health_message' => $health['message'],
            'capabilities' => $this->capabilities($method),
            'transactions_count' => PaymentTransaction::query()->where('payment_method_id', $method->id)->count(),
            'confirmed_amount' => (float) PaymentTransaction::query()->where('payment_method_id', $method->id)->where('status', 'confirmed')->sum('amount'),
            'failed_attempts' => PaymentAttempt::query()->where('provider_key', $method->provider_key)->where('status', 'failed')->count(),
            'failed_payouts' => PayoutJob::query()->where('provider_key', $method->provider_key)->where('status', 'failed')->count(),
            'webhook_issues' => WebhookLog::query()
                ->where('provider', $method->provider_key)
                ->where(fn ($query) => $query->where('signature_valid', false)->orWhere('processing_status', 'failed'))
                ->count(),
        ];
    }

    private function providerDetail(PaymentMethod $method): array
    {
        return array_merge($this->providerSummary($method), [
            'config' => [
                'configured_keys' => collect($method->config ?? [])->keys()->values(),
                'has_credentials' => collect($method->config ?? [])->filter(fn ($value) => filled($value))->isNotEmpty(),
                'masked' => $this->maskConfig($method->config ?? []),
            ],
            'created_at' => optional($method->created_at)->toDateTimeString(),
            'updated_at' => optional($method->updated_at)->toDateTimeString(),
        ]);
    }

    private function methodRow(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'provider_key' => $method->provider_key,
            'display_name' => $method->display_name,
            'logo_url' => $method->logo_url,
            'type' => $method->type,
            'driver' => class_basename($method->driver_class),
            'driver_class' => $method->driver_class,
            'is_active' => $method->is_active,
            'status' => $method->is_active ? 'active' : 'disabled',
            'sort_order' => $method->sort_order,
            'capabilities' => $this->capabilities($method),
            'health' => $this->driverHealth($method),
        ];
    }

    private function transactionRow(PaymentTransaction $transaction): array
    {
        return [
            'reference' => $transaction->reference,
            'order_reference' => $transaction->order_reference,
            'provider_key' => $transaction->paymentMethod?->provider_key,
            'provider_name' => $transaction->paymentMethod?->display_name,
            'provider_reference' => $transaction->provider_reference,
            'status' => $transaction->status,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'phone' => $transaction->phone,
            'payer_name' => $transaction->payer_name,
            'payment_context' => Arr::get($transaction->metadata ?? [], 'payment_context'),
            'source_service' => Arr::get($transaction->metadata ?? [], 'source_service'),
            'confirmed_at' => optional($transaction->confirmed_at)->toDateTimeString(),
            'failed_at' => optional($transaction->failed_at)->toDateTimeString(),
            'created_at' => optional($transaction->created_at)->toDateTimeString(),
        ];
    }

    private function transactionDetail(PaymentTransaction $transaction): array
    {
        return array_merge($this->transactionRow($transaction), [
            'callback_url' => $transaction->callback_url,
            'metadata' => $transaction->metadata ?? [],
            'escrow_wallets' => $transaction->escrowWallets
                ->map(fn (EscrowWallet $wallet) => $this->escrowRow($wallet))
                ->values(),
        ]);
    }

    private function attemptRow(PaymentAttempt $attempt): array
    {
        return [
            'attempt_reference' => $attempt->attempt_reference,
            'order_reference' => $attempt->order_reference,
            'provider_key' => $attempt->provider_key,
            'status' => $attempt->status,
            'amount' => (float) $attempt->amount,
            'currency' => $attempt->currency,
            'payer_phone' => $attempt->payer_phone,
            'failure_reason' => $attempt->failure_reason,
            'initiated_at' => optional($attempt->initiated_at)->toDateTimeString(),
            'confirmed_at' => optional($attempt->confirmed_at)->toDateTimeString(),
            'failed_at' => optional($attempt->failed_at)->toDateTimeString(),
        ];
    }

    private function escrowRow(EscrowWallet $wallet): array
    {
        return [
            'order_reference' => $wallet->order_reference,
            'transaction_reference' => $wallet->transaction?->reference,
            'provider_key' => $wallet->transaction?->paymentMethod?->provider_key,
            'amount' => (float) $wallet->amount,
            'currency' => $wallet->currency,
            'status' => $wallet->status,
            'held_at' => optional($wallet->held_at)->toDateTimeString(),
            'released_at' => optional($wallet->released_at)->toDateTimeString(),
            'created_at' => optional($wallet->created_at)->toDateTimeString(),
            'splits' => $wallet->relationLoaded('splits')
                ? $wallet->splits->map(fn (EscrowSplit $split) => [
                    'recipient_type' => $split->recipient_type,
                    'recipient_id' => $split->recipient_id,
                    'gross_amount' => (float) ($split->gross_amount ?? $split->amount),
                    'platform_fee' => (float) ($split->platform_fee ?? 0),
                    'net_amount' => (float) ($split->net_amount ?? $split->amount),
                    'currency' => $split->currency,
                    'status' => $split->status ?? null,
                    'available_at' => optional($split->available_at ?? null)->toDateTimeString(),
                    'payout' => $split->payoutJob ? $this->payoutRow($split->payoutJob) : null,
                ])->values()
                : [],
        ];
    }

    private function payoutRow(PayoutJob $payout): array
    {
        return [
            'reference' => $payout->reference,
            'recipient_type' => $payout->recipient_type,
            'recipient_id' => $payout->recipient_id,
            'amount' => (float) $payout->amount,
            'currency' => $payout->currency,
            'provider_key' => $payout->provider_key,
            'provider_reference' => $payout->provider_reference,
            'status' => $payout->status,
            'attempts' => $payout->attempts,
            'error_message' => $payout->error_message,
            'order_reference' => $payout->escrowSplit?->escrowWallet?->order_reference,
            'last_attempted_at' => optional($payout->last_attempted_at)->toDateTimeString(),
            'completed_at' => optional($payout->completed_at)->toDateTimeString(),
            'created_at' => optional($payout->created_at)->toDateTimeString(),
        ];
    }

    private function refundRow(RefundRequest $refund): array
    {
        return [
            'reference' => $refund->reference,
            'order_reference' => $refund->order_reference,
            'transaction_reference' => $refund->transaction?->reference,
            'source_service' => $refund->source_service,
            'return_reference' => $refund->return_reference,
            'dispute_reference' => $refund->dispute_reference,
            'requested_by_type' => $refund->requested_by_type,
            'requested_by_id' => $refund->requested_by_id,
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'provider_key' => $refund->provider_key ?: $refund->transaction?->paymentMethod?->provider_key,
            'provider_reference' => $refund->provider_reference,
            'status' => $refund->status,
            'reason' => $refund->reason,
            'review_note' => $refund->review_note,
            'failure_reason' => $refund->failure_reason,
            'requested_at' => optional($refund->requested_at)->toDateTimeString(),
            'approved_at' => optional($refund->approved_at)->toDateTimeString(),
            'rejected_at' => optional($refund->rejected_at)->toDateTimeString(),
            'processed_at' => optional($refund->processed_at)->toDateTimeString(),
            'completed_at' => optional($refund->completed_at)->toDateTimeString(),
            'failed_at' => optional($refund->failed_at)->toDateTimeString(),
            'created_at' => optional($refund->created_at)->toDateTimeString(),
        ];
    }

    private function sendRefundCallback(RefundRequest $refund): void
    {
        if (! $refund->callback_url) {
            return;
        }

        try {
            Http::withHeaders([
                'X-Internal-Key' => env('MAIN_PLATFORM_INTERNAL_KEY')
                    ?: env('TRUST_MAIN_INTERNAL_KEY')
                    ?: config('services.main_platform.internal_key')
                    ?: env('INTERNAL_SERVICE_KEY'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(8)->post($refund->callback_url, [
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
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function webhookRow(WebhookLog $webhook): array
    {
        return [
            'id' => $webhook->id,
            'provider' => $webhook->provider,
            'endpoint' => $webhook->endpoint,
            'signature_valid' => $webhook->signature_valid,
            'processing_status' => $webhook->processing_status,
            'error_message' => $webhook->error_message,
            'matched_transaction' => $webhook->matchedTransaction?->reference,
            'matched_transaction_status' => $webhook->matchedTransaction?->status,
            'processed_at' => optional($webhook->processed_at)->toDateTimeString(),
            'created_at' => optional($webhook->created_at)->toDateTimeString(),
            'payload_summary' => $this->payloadSummary($webhook->payload ?? []),
        ];
    }

    private function reconciliationItem(string $type, string $title, string $description, ?string $status, array $payload): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'payload' => $payload,
            'occurred_at' => $payload['created_at'] ?? $payload['sent_at'] ?? null,
        ];
    }

    private function statusBreakdown(Builder $query, string $column): array
    {
        return $query
            ->select($column, DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy($column)
            ->get()
            ->map(fn ($row) => [
                'label' => $row->{$column} ?: 'unknown',
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();
    }

    private function capabilities(PaymentMethod $method): array
    {
        $driverClass = $method->driver_class;
        $driverExists = class_exists($driverClass);

        return [
            'collection' => $driverExists && ! is_a($driverClass, \App\Drivers\ManualPayoutDriver::class, true),
            'payout' => $driverExists && method_exists($driverClass, 'payout'),
            'refund' => $driverExists && method_exists($driverClass, 'refund'),
            'webhook' => $driverExists && method_exists($driverClass, 'verifyWebhook') && method_exists($driverClass, 'normalizeWebhookPayload'),
        ];
    }

    private function driverHealth(PaymentMethod $method): array
    {
        $driverClass = $method->driver_class;

        if (! class_exists($driverClass)) {
            return [
                'status' => 'driver_missing',
                'driver_exists' => false,
                'driver_valid' => false,
                'message' => 'Driver class does not exist.',
            ];
        }

        try {
            $driver = app($driverClass, ['config' => $method->config ?? []]);
        } catch (Throwable $exception) {
            return [
                'status' => 'driver_error',
                'driver_exists' => true,
                'driver_valid' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $valid = $driver instanceof \App\Contracts\PaymentDriverInterface;

        return [
            'status' => $valid ? ($method->is_active ? 'operational' : 'disabled') : 'invalid_driver',
            'driver_exists' => true,
            'driver_valid' => $valid,
            'message' => $valid ? 'Driver is loadable. External provider health requires provider API support.' : 'Driver does not implement PaymentDriverInterface.',
        ];
    }

    private function methodByProvider(string $provider): PaymentMethod
    {
        return PaymentMethod::query()
            ->with('provider')
            ->where('provider_key', $provider)
            ->firstOrFail();
    }

    private function paginate(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'items' => $paginator->getCollection()->map($map)->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function maskConfig(array $config): array
    {
        return collect($config)->mapWithKeys(function (mixed $value, string|int $key): array {
            $keyName = Str::lower((string) $key);

            if (Str::contains($keyName, ['secret', 'token', 'password', 'key', 'client'])) {
                return [$key => filled($value) ? '********' : null];
            }

            return [$key => is_scalar($value) ? $value : '[configured]'];
        })->all();
    }

    private function payloadSummary(array $payload): array
    {
        return collect(Arr::only($payload, [
            'reference',
            'orderReference',
            'order_reference',
            'providerReference',
            'provider_reference',
            'status',
            'amount',
            'currency',
            'event',
            'eventType',
        ]))->filter(fn ($value) => filled($value))->all();
    }

    private function authorizeInternal(Request $request): void
    {
        $provided = (string) $request->header('X-Internal-Key', '');
        $expectedKeys = collect([
            config('services.toms.internal_key'),
            env('TOMS_INTERNAL_KEY'),
            env('PAYMENT_SERVICE_INTERNAL_KEY'),
            env('INTERNAL_SERVICE_KEY'),
            config('services.main_platform.internal_key'),
        ])->filter()->values();

        abort_if($provided === '' || $expectedKeys->isEmpty() || ! $expectedKeys->contains(fn ($key) => hash_equals((string) $key, $provided)), 401, 'Unauthorized.');
    }
}
