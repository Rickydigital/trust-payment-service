<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\EscrowWallet;
use App\Models\EscrowSplit;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\PayoutJob;
use App\Models\WebhookLog;
use Illuminate\Http\Request;

class TomsCrmController extends Controller
{
    public function search(Request $request)
    {
        $this->authorizeInternal($request);

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['data' => ['results' => []]]);
        }

        $like = '%' . $q . '%';
        $results = collect();

        PaymentTransaction::query()
            ->where(fn ($query) => $query
                ->where('reference', 'like', $like)
                ->orWhere('order_reference', 'like', $like)
                ->orWhere('provider_reference', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('payer_name', 'like', $like))
            ->limit(10)
            ->get()
            ->each(fn (PaymentTransaction $transaction) => $results->push($this->transactionResult($transaction)));

        PaymentAttempt::query()
            ->where(fn ($query) => $query
                ->where('attempt_reference', 'like', $like)
                ->orWhere('order_reference', 'like', $like)
                ->orWhere('payer_phone', 'like', $like)
                ->orWhere('provider_key', 'like', $like))
            ->limit(10)
            ->get()
            ->each(fn (PaymentAttempt $attempt) => $results->push([
                'type' => 'payment_attempt',
                'id' => (string) $attempt->attempt_reference,
                'name' => 'Attempt ' . $attempt->attempt_reference,
                'email' => null,
                'phone' => $attempt->payer_phone,
                'status' => $attempt->status,
                'summary' => [
                    'order_reference' => $attempt->order_reference,
                    'amount' => $attempt->amount,
                    'currency' => $attempt->currency,
                ],
            ]));

        EscrowWallet::query()
            ->where('order_reference', 'like', $like)
            ->limit(10)
            ->get()
            ->each(fn (EscrowWallet $wallet) => $results->push([
                'type' => 'escrow',
                'id' => (string) $wallet->order_reference,
                'name' => 'Escrow ' . $wallet->order_reference,
                'email' => null,
                'phone' => null,
                'status' => $wallet->status,
                'summary' => [
                    'amount' => $wallet->amount,
                    'currency' => $wallet->currency,
                ],
            ]));

        PayoutJob::query()
            ->where(fn ($query) => $query
                ->where('reference', 'like', $like)
                ->orWhere('provider_reference', 'like', $like)
                ->orWhere('recipient_id', 'like', $like))
            ->limit(10)
            ->get()
            ->each(fn (PayoutJob $payout) => $results->push([
                'type' => 'payout',
                'id' => (string) $payout->reference,
                'name' => 'Payout ' . $payout->reference,
                'email' => null,
                'phone' => null,
                'status' => $payout->status,
                'summary' => [
                    'recipient_type' => $payout->recipient_type,
                    'recipient_id' => $payout->recipient_id,
                    'amount' => $payout->amount,
                    'currency' => $payout->currency,
                ],
            ]));

        return response()->json([
            'data' => [
                'results' => $results->unique(fn (array $item) => $item['type'] . ':' . $item['id'])->values(),
            ],
        ]);
    }

    public function profile(Request $request)
    {
        $this->authorizeInternal($request);

        $type = (string) $request->query('type', 'payment_transaction');
        $id = (string) $request->query('id');

        return match ($type) {
            'payer' => $this->payerProfile($id),
            'payment_attempt' => $this->attemptProfile($id),
            'escrow' => $this->escrowProfile($id),
            'payout' => $this->payoutProfile($id),
            default => $this->transactionProfile($id),
        };
    }

    private function payerProfile(string $payer)
    {
        $transactions = PaymentTransaction::query()
            ->with('paymentMethod')
            ->where('phone', $payer)
            ->orWhere('payer_name', 'like', '%' . $payer . '%')
            ->latest()
            ->limit(15)
            ->get();
        $attempts = PaymentAttempt::query()
            ->where('payer_phone', $payer)
            ->latest()
            ->limit(15)
            ->get();

        return response()->json([
            'data' => [
                'profile' => [
                    'id' => $payer,
                    'name' => $transactions->first()?->payer_name ?: 'Payment payer ' . $payer,
                    'email' => null,
                    'phone' => $payer,
                    'status' => $transactions->contains(fn (PaymentTransaction $transaction) => $transaction->status === 'failed') ? 'has_failed_payments' : 'active',
                ],
                'sections' => [
                    [
                        'title' => 'Payment Payer',
                        'items' => [
                            ['label' => 'Transactions', 'value' => $transactions->count()],
                            ['label' => 'Attempts', 'value' => $attempts->count()],
                            ['label' => 'Failed Attempts', 'value' => $attempts->where('status', 'failed')->count()],
                            ['label' => 'Confirmed Transactions', 'value' => $transactions->where('status', 'confirmed')->count()],
                        ],
                    ],
                ],
                'tables' => [
                    $this->table('Transactions', [
                        ['key' => 'reference', 'label' => 'Reference'],
                        ['key' => 'order', 'label' => 'Order'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'method', 'label' => 'Method'],
                        ['key' => 'amount', 'label' => 'Amount'],
                        ['key' => 'created_at', 'label' => 'Created'],
                    ], $transactions->map(fn (PaymentTransaction $transaction) => [
                        'reference' => $transaction->reference,
                        'order' => $transaction->order_reference,
                        'status' => $transaction->status,
                        'method' => $transaction->paymentMethod?->name,
                        'amount' => $transaction->currency . ' ' . $transaction->amount,
                        'created_at' => optional($transaction->created_at)->toDateTimeString(),
                    ])),
                    $this->table('Attempts', [
                        ['key' => 'reference', 'label' => 'Reference'],
                        ['key' => 'order', 'label' => 'Order'],
                        ['key' => 'provider', 'label' => 'Provider'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'failure', 'label' => 'Failure'],
                    ], $attempts->map(fn (PaymentAttempt $attempt) => [
                        'reference' => $attempt->attempt_reference,
                        'order' => $attempt->order_reference,
                        'provider' => $attempt->provider_key,
                        'status' => $attempt->status,
                        'failure' => $attempt->failure_reason,
                    ])),
                ],
                'timeline' => collect()
                    ->merge($transactions->map(fn (PaymentTransaction $transaction) => $this->timeline('payment_transaction', 'Payment ' . $transaction->reference, $transaction->status . ' / ' . $transaction->currency . ' ' . $transaction->amount, $transaction->created_at)))
                    ->merge($attempts->map(fn (PaymentAttempt $attempt) => $this->timeline('payment_attempt', 'Attempt ' . $attempt->attempt_reference, $attempt->status . ' / ' . $attempt->failure_reason, $attempt->created_at)))
                    ->sortByDesc('occurred_at')
                    ->values(),
            ],
        ]);
    }

    public function workItems(Request $request)
    {
        $this->authorizeInternal($request);

        $items = collect();

        PaymentTransaction::query()
            ->with('paymentMethod')
            ->whereIn('status', ['failed', 'cancelled', 'disputed'])
            ->latest()
            ->limit(40)
            ->get()
            ->each(fn (PaymentTransaction $transaction) => $items->push([
                'item_type' => 'payment_transaction',
                'external_id' => (string) $transaction->reference,
                'external_number' => $transaction->reference,
                'title' => 'Payment transaction ' . $transaction->status,
                'description' => 'Payment transaction requires review.',
                'status' => $transaction->status,
                'priority' => $transaction->status === 'failed' ? 'high' : 'medium',
                'category' => 'payment',
                'contact' => [
                    'type' => 'payer',
                    'id' => (string) ($transaction->phone ?: $transaction->reference),
                    'name' => $transaction->payer_name,
                    'email' => null,
                    'phone' => $transaction->phone,
                ],
                'related' => [
                    'type' => 'order',
                    'id' => (string) $transaction->order_reference,
                    'label' => $transaction->order_reference,
                ],
                'payload' => [
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'method' => $transaction->paymentMethod?->name,
                    'provider_reference' => $transaction->provider_reference,
                ],
                'remote_actions' => [],
                'occurred_at' => optional($transaction->failed_at ?: $transaction->updated_at)->toIso8601String(),
            ]));

        PaymentAttempt::query()
            ->whereIn('status', ['failed', 'cancelled'])
            ->latest()
            ->limit(40)
            ->get()
            ->each(fn (PaymentAttempt $attempt) => $items->push([
                'item_type' => 'payment_attempt',
                'external_id' => (string) $attempt->attempt_reference,
                'external_number' => $attempt->attempt_reference,
                'title' => 'Payment attempt ' . $attempt->status,
                'description' => $attempt->failure_reason ?: 'Payment attempt requires review.',
                'status' => $attempt->status,
                'priority' => $attempt->status === 'failed' ? 'high' : 'medium',
                'category' => 'payment',
                'contact' => [
                    'type' => 'payer',
                    'id' => (string) ($attempt->payer_phone ?: $attempt->attempt_reference),
                    'name' => null,
                    'email' => null,
                    'phone' => $attempt->payer_phone,
                ],
                'related' => [
                    'type' => 'order',
                    'id' => (string) $attempt->order_reference,
                    'label' => $attempt->order_reference,
                ],
                'payload' => [
                    'provider_key' => $attempt->provider_key,
                    'amount' => $attempt->amount,
                    'currency' => $attempt->currency,
                    'failure_reason' => $attempt->failure_reason,
                ],
                'remote_actions' => [],
                'occurred_at' => optional($attempt->failed_at ?: $attempt->updated_at)->toIso8601String(),
            ]));

        PayoutJob::query()
            ->whereIn('status', ['failed', 'pending', 'hold'])
            ->latest()
            ->limit(40)
            ->get()
            ->each(fn (PayoutJob $payout) => $items->push([
                'item_type' => 'payout',
                'external_id' => (string) $payout->reference,
                'external_number' => $payout->reference,
                'title' => 'Payout ' . $payout->status,
                'description' => $payout->error_message ?: 'Payout requires review.',
                'status' => $payout->status,
                'priority' => $payout->status === 'failed' ? 'high' : 'medium',
                'category' => 'payment',
                'contact' => [
                    'type' => $payout->recipient_type ?: 'recipient',
                    'id' => (string) $payout->recipient_id,
                    'name' => null,
                    'email' => null,
                    'phone' => null,
                ],
                'related' => [
                    'type' => 'escrow_split',
                    'id' => (string) $payout->escrow_split_id,
                    'label' => 'Split #' . $payout->escrow_split_id,
                ],
                'payload' => [
                    'amount' => $payout->amount,
                    'currency' => $payout->currency,
                    'provider_key' => $payout->provider_key,
                    'provider_reference' => $payout->provider_reference,
                    'attempts' => $payout->attempts,
                    'error_message' => $payout->error_message,
                ],
                'remote_actions' => [],
                'occurred_at' => optional($payout->last_attempted_at ?: $payout->updated_at)->toIso8601String(),
            ]));

        WebhookLog::query()
            ->where(fn ($query) => $query
                ->where('signature_valid', false)
                ->orWhereIn('processing_status', ['failed', 'error']))
            ->latest()
            ->limit(40)
            ->get()
            ->each(fn (WebhookLog $webhook) => $items->push([
                'item_type' => 'payment_webhook',
                'external_id' => (string) $webhook->id,
                'external_number' => 'Webhook #' . $webhook->id,
                'title' => 'Payment webhook requires review',
                'description' => $webhook->error_message,
                'status' => $webhook->processing_status,
                'priority' => $webhook->signature_valid ? 'high' : 'urgent',
                'category' => 'payment',
                'contact' => null,
                'related' => [
                    'type' => 'payment_transaction',
                    'id' => (string) $webhook->matched_transaction_id,
                    'label' => 'Transaction #' . $webhook->matched_transaction_id,
                ],
                'payload' => [
                    'provider' => $webhook->provider,
                    'endpoint' => $webhook->endpoint,
                    'signature_valid' => $webhook->signature_valid,
                    'error_message' => $webhook->error_message,
                ],
                'remote_actions' => [],
                'occurred_at' => optional($webhook->created_at)->toIso8601String(),
            ]));

        $items = $this->filterItems($items, $request);

        return response()->json(['data' => ['items' => $items->values()]]);
    }

    public function resolveWorkItem(Request $request, string $type, string $id)
    {
        $this->authorizeInternal($request);

        return response()->json([
            'success' => false,
            'message' => 'Payment CRM work items are reviewed in TOMS. Money movement and provider state changes remain inside Payment operations.',
        ], 422);
    }

    private function transactionProfile(string $reference)
    {
        $transaction = PaymentTransaction::query()
            ->with(['paymentMethod', 'escrowWallets.splits.payoutJob'])
            ->where('reference', $reference)
            ->orWhere('order_reference', $reference)
            ->firstOrFail();
        $attempts = PaymentAttempt::query()
            ->where('payment_transaction_id', $transaction->id)
            ->orWhere('order_reference', $transaction->order_reference)
            ->latest()
            ->limit(12)
            ->get();
        $webhooks = WebhookLog::query()
            ->where('matched_transaction_id', $transaction->id)
            ->latest()
            ->limit(12)
            ->get();
        $splits = $transaction->escrowWallets->flatMap(fn (EscrowWallet $wallet) => $wallet->splits);

        return response()->json([
            'data' => [
                'profile' => [
                    'id' => $transaction->reference,
                    'name' => 'Payment ' . $transaction->reference,
                    'email' => null,
                    'phone' => $transaction->phone,
                    'status' => $transaction->status,
                ],
                'sections' => [
                    [
                        'title' => 'Payment',
                        'items' => [
                            ['label' => 'Reference', 'value' => $transaction->reference],
                            ['label' => 'Order', 'value' => $transaction->order_reference],
                            ['label' => 'Status', 'value' => $transaction->status],
                            ['label' => 'Amount', 'value' => $transaction->currency . ' ' . $transaction->amount],
                            ['label' => 'Method', 'value' => $transaction->paymentMethod?->name],
                            ['label' => 'Provider Ref', 'value' => $transaction->provider_reference],
                        ],
                    ],
                    [
                        'title' => 'Attempts And Escrow',
                        'items' => [
                            ['label' => 'Attempts', 'value' => PaymentAttempt::query()->where('payment_transaction_id', $transaction->id)->count()],
                            ['label' => 'Escrow Status', 'value' => $transaction->escrowWallets->first()?->status],
                            ['label' => 'Confirmed At', 'value' => optional($transaction->confirmed_at)->toDateTimeString()],
                        ],
                    ],
                ],
                'tables' => [
                    $this->table('Payment Attempts', [
                        ['key' => 'reference', 'label' => 'Reference'],
                        ['key' => 'provider', 'label' => 'Provider'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'amount', 'label' => 'Amount'],
                        ['key' => 'failure', 'label' => 'Failure'],
                        ['key' => 'created_at', 'label' => 'Created'],
                    ], $attempts->map(fn (PaymentAttempt $attempt) => [
                        'reference' => $attempt->attempt_reference,
                        'provider' => $attempt->provider_key,
                        'status' => $attempt->status,
                        'amount' => $attempt->currency . ' ' . $attempt->amount,
                        'failure' => $attempt->failure_reason,
                        'created_at' => optional($attempt->created_at)->toDateTimeString(),
                    ])),
                    $this->table('Escrow Splits', [
                        ['key' => 'recipient', 'label' => 'Recipient'],
                        ['key' => 'gross', 'label' => 'Gross'],
                        ['key' => 'fee', 'label' => 'Fee'],
                        ['key' => 'net', 'label' => 'Net'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'payout', 'label' => 'Payout'],
                    ], $splits->map(fn (EscrowSplit $split) => [
                        'recipient' => $split->recipient_type . ' #' . $split->recipient_id,
                        'gross' => $split->currency . ' ' . $split->gross_amount,
                        'fee' => $split->currency . ' ' . $split->platform_fee,
                        'net' => $split->currency . ' ' . $split->net_amount,
                        'status' => $split->status,
                        'payout' => $split->payoutJob?->reference,
                    ])),
                    $this->table('Webhooks', [
                        ['key' => 'provider', 'label' => 'Provider'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'signature', 'label' => 'Signature'],
                        ['key' => 'error', 'label' => 'Error'],
                        ['key' => 'created_at', 'label' => 'Created'],
                    ], $webhooks->map(fn (WebhookLog $webhook) => [
                        'provider' => $webhook->provider,
                        'status' => $webhook->processing_status,
                        'signature' => $webhook->signature_valid ? 'valid' : 'invalid',
                        'error' => $webhook->error_message,
                        'created_at' => optional($webhook->created_at)->toDateTimeString(),
                    ])),
                ],
                'timeline' => collect()
                    ->merge($attempts->map(fn (PaymentAttempt $attempt) => $this->timeline('payment_attempt', $attempt->attempt_reference, $attempt->status . ' / ' . $attempt->failure_reason, $attempt->created_at)))
                    ->merge($webhooks->map(fn (WebhookLog $webhook) => $this->timeline('webhook', 'Webhook ' . $webhook->provider, $webhook->processing_status . ' / ' . $webhook->error_message, $webhook->created_at)))
                    ->push($this->timeline('payment_transaction', 'Payment ' . $transaction->reference, $transaction->status, $transaction->created_at))
                    ->sortByDesc('occurred_at')
                    ->values(),
            ],
        ]);
    }

    private function attemptProfile(string $reference)
    {
        $attempt = PaymentAttempt::query()
            ->with('transaction.paymentMethod')
            ->where('attempt_reference', $reference)
            ->orWhere('order_reference', $reference)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'profile' => [
                    'id' => $attempt->attempt_reference,
                    'name' => 'Payment Attempt ' . $attempt->attempt_reference,
                    'email' => null,
                    'phone' => $attempt->payer_phone,
                    'status' => $attempt->status,
                ],
                'sections' => [
                    [
                        'title' => 'Attempt',
                        'items' => [
                            ['label' => 'Reference', 'value' => $attempt->attempt_reference],
                            ['label' => 'Order', 'value' => $attempt->order_reference],
                            ['label' => 'Provider', 'value' => $attempt->provider_key],
                            ['label' => 'Status', 'value' => $attempt->status],
                            ['label' => 'Failure', 'value' => $attempt->failure_reason],
                        ],
                    ],
                ],
                'tables' => [
                    $this->table('Related Transaction', [
                        ['key' => 'reference', 'label' => 'Reference'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'method', 'label' => 'Method'],
                        ['key' => 'provider_reference', 'label' => 'Provider Ref'],
                        ['key' => 'confirmed_at', 'label' => 'Confirmed'],
                    ], $attempt->transaction ? [[
                        'reference' => $attempt->transaction->reference,
                        'status' => $attempt->transaction->status,
                        'method' => $attempt->transaction->paymentMethod?->name,
                        'provider_reference' => $attempt->transaction->provider_reference,
                        'confirmed_at' => optional($attempt->transaction->confirmed_at)->toDateTimeString(),
                    ]] : []),
                ],
                'timeline' => collect([
                    $this->timeline('payment_attempt', 'Attempt initiated', $attempt->status, $attempt->initiated_at ?: $attempt->created_at),
                    $this->timeline('payment_attempt', 'Attempt confirmed', $attempt->provider_key, $attempt->confirmed_at),
                    $this->timeline('payment_attempt', 'Attempt failed', $attempt->failure_reason, $attempt->failed_at),
                    $this->timeline('payment_attempt', 'Attempt cancelled', null, $attempt->cancelled_at),
                ])->filter(fn (array $event) => filled($event['occurred_at']))->values(),
            ],
        ]);
    }

    private function escrowProfile(string $orderReference)
    {
        $wallet = EscrowWallet::query()
            ->with(['transaction', 'splits.payoutJob'])
            ->where('order_reference', $orderReference)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'profile' => [
                    'id' => $wallet->order_reference,
                    'name' => 'Escrow ' . $wallet->order_reference,
                    'email' => null,
                    'phone' => null,
                    'status' => $wallet->status,
                ],
                'sections' => [
                    [
                        'title' => 'Escrow',
                        'items' => [
                            ['label' => 'Order', 'value' => $wallet->order_reference],
                            ['label' => 'Status', 'value' => $wallet->status],
                            ['label' => 'Amount', 'value' => $wallet->currency . ' ' . $wallet->amount],
                            ['label' => 'Held At', 'value' => optional($wallet->held_at)->toDateTimeString()],
                            ['label' => 'Released At', 'value' => optional($wallet->released_at)->toDateTimeString()],
                            ['label' => 'Splits', 'value' => $wallet->splits()->count()],
                        ],
                    ],
                ],
                'tables' => [
                    $this->table('Escrow Splits', [
                        ['key' => 'recipient', 'label' => 'Recipient'],
                        ['key' => 'gross', 'label' => 'Gross'],
                        ['key' => 'platform_fee', 'label' => 'Platform Fee'],
                        ['key' => 'net', 'label' => 'Net'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'payout', 'label' => 'Payout'],
                        ['key' => 'available_at', 'label' => 'Available'],
                    ], $wallet->splits->map(fn (EscrowSplit $split) => [
                        'recipient' => $split->recipient_type . ' #' . $split->recipient_id,
                        'gross' => $split->currency . ' ' . $split->gross_amount,
                        'platform_fee' => $split->currency . ' ' . $split->platform_fee,
                        'net' => $split->currency . ' ' . $split->net_amount,
                        'status' => $split->status,
                        'payout' => $split->payoutJob?->reference,
                        'available_at' => optional($split->available_at)->toDateTimeString(),
                    ])),
                ],
                'timeline' => collect()
                    ->push($this->timeline('escrow', 'Escrow held', $wallet->currency . ' ' . $wallet->amount, $wallet->held_at))
                    ->push($this->timeline('escrow', 'Release requested', $wallet->status, $wallet->release_requested_at))
                    ->push($this->timeline('escrow', 'Released', $wallet->status, $wallet->released_at))
                    ->filter(fn (array $event) => filled($event['occurred_at']))
                    ->values(),
            ],
        ]);
    }

    private function payoutProfile(string $reference)
    {
        $payout = PayoutJob::query()->with('escrowSplit.escrowWallet')->where('reference', $reference)->firstOrFail();

        return response()->json([
            'data' => [
                'profile' => [
                    'id' => $payout->reference,
                    'name' => 'Payout ' . $payout->reference,
                    'email' => null,
                    'phone' => null,
                    'status' => $payout->status,
                ],
                'sections' => [
                    [
                        'title' => 'Payout',
                        'items' => [
                            ['label' => 'Reference', 'value' => $payout->reference],
                            ['label' => 'Recipient', 'value' => $payout->recipient_type . ' #' . $payout->recipient_id],
                            ['label' => 'Status', 'value' => $payout->status],
                            ['label' => 'Amount', 'value' => $payout->currency . ' ' . $payout->amount],
                            ['label' => 'Provider', 'value' => $payout->provider_key],
                            ['label' => 'Error', 'value' => $payout->error_message],
                        ],
                    ],
                ],
                'tables' => [
                    $this->table('Payout Source', [
                        ['key' => 'escrow', 'label' => 'Escrow'],
                        ['key' => 'recipient', 'label' => 'Recipient'],
                        ['key' => 'gross', 'label' => 'Gross'],
                        ['key' => 'fee', 'label' => 'Fee'],
                        ['key' => 'net', 'label' => 'Net'],
                        ['key' => 'split_status', 'label' => 'Split Status'],
                    ], $payout->escrowSplit ? [[
                        'escrow' => $payout->escrowSplit->escrowWallet?->order_reference,
                        'recipient' => $payout->escrowSplit->recipient_type . ' #' . $payout->escrowSplit->recipient_id,
                        'gross' => $payout->escrowSplit->currency . ' ' . $payout->escrowSplit->gross_amount,
                        'fee' => $payout->escrowSplit->currency . ' ' . $payout->escrowSplit->platform_fee,
                        'net' => $payout->escrowSplit->currency . ' ' . $payout->escrowSplit->net_amount,
                        'split_status' => $payout->escrowSplit->status,
                    ]] : []),
                ],
                'timeline' => collect([
                    $this->timeline('payout', 'Payout created', $payout->status, $payout->created_at),
                    $this->timeline('payout', 'Last attempted', $payout->error_message, $payout->last_attempted_at),
                    $this->timeline('payout', 'Completed', $payout->provider_reference, $payout->completed_at),
                ])->filter(fn (array $event) => filled($event['occurred_at']))->values(),
            ],
        ]);
    }

    private function transactionResult(PaymentTransaction $transaction): array
    {
        return [
            'type' => 'payment_transaction',
            'id' => (string) $transaction->reference,
            'name' => $transaction->payer_name ?: 'Payment ' . $transaction->reference,
            'email' => null,
            'phone' => $transaction->phone,
            'status' => $transaction->status,
            'summary' => [
                'order_reference' => $transaction->order_reference,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'provider_reference' => $transaction->provider_reference,
            ],
        ];
    }

    private function filterItems($items, Request $request)
    {
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));

        return $items
            ->when($type !== '', fn ($items) => $items->where('item_type', $type))
            ->when($status !== '', fn ($items) => $items->where('status', $status))
            ->when($q !== '', function ($items) use ($q) {
                $needle = mb_strtolower($q);

                return $items->filter(fn (array $item) => str_contains(mb_strtolower(json_encode($item)), $needle));
            })
            ->sortByDesc('occurred_at');
    }

    private function table(string $title, array $columns, $rows): array
    {
        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => collect($rows)->values()->all(),
        ];
    }

    private function timeline(string $type, string $title, ?string $description, mixed $occurredAt): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'occurred_at' => optional($occurredAt)->toDateTimeString(),
        ];
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
