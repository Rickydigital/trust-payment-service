<?php

use App\Http\Controllers\Api\EscrowController;
use App\Http\Controllers\Api\Internal\TomsCrmController as InternalTomsCrmController;
use App\Http\Controllers\Api\Internal\TomsPaymentOperationsController as InternalTomsPaymentOperationsController;
use App\Http\Controllers\Api\Internal\RefundRequestController as InternalRefundRequestController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'success' => true,
    'service' => 'trust-payment-service',
    'status' => 'healthy',
    'checked_at' => now()->toIso8601String(),
]));

Route::middleware('auth.delegated')->group(function () {
    Route::post('/initiate', [PaymentController::class, 'initiate'])->name('payment.initiate');
    Route::get('/orders/{order_reference}/attempts', [PaymentController::class, 'attempts']);
});

Route::middleware('auth.internal')->group(function () {
    Route::get('/escrow/{order_reference}', [EscrowController::class, 'show']);
    Route::post('/escrow/release', [EscrowController::class, 'release']);
    Route::post('/internal/escrow/{order_reference}/release-seller', [EscrowController::class, 'releaseSeller']);
    Route::post('/internal/escrow/{order_reference}/release-delivery', [EscrowController::class, 'releaseDelivery']);
    Route::post('/internal/escrow/{order_reference}/release-user', [EscrowController::class, 'releaseUser']);
    Route::get('/wallets/{owner_type}/{owner_id}/summary', [WalletController::class, 'summary']);
    Route::get('/wallets/{owner_type}/{owner_id}/ledger', [WalletController::class, 'ledger']);
    Route::post('/withdrawals/preview', [WalletController::class, 'previewWithdrawal']);
    Route::post('/withdrawals', [WalletController::class, 'withdraw']);
    Route::post('/internal/refunds', [InternalRefundRequestController::class, 'store']);
});

Route::get('/internal/toms/crm/search', [InternalTomsCrmController::class, 'search']);
Route::get('/internal/toms/crm/profile', [InternalTomsCrmController::class, 'profile']);
Route::get('/internal/toms/crm/work-items', [InternalTomsCrmController::class, 'workItems']);
Route::post('/internal/toms/crm/work-items/{type}/{id}/resolve', [InternalTomsCrmController::class, 'resolveWorkItem']);

Route::prefix('/internal/toms/payment')->group(function () {
    Route::get('/dashboard', [InternalTomsPaymentOperationsController::class, 'dashboard']);
    Route::get('/providers', [InternalTomsPaymentOperationsController::class, 'providers']);
    Route::get('/providers/{provider}', [InternalTomsPaymentOperationsController::class, 'provider']);
    Route::post('/providers/{provider}/health-check', [InternalTomsPaymentOperationsController::class, 'providerHealth']);
    Route::post('/providers/{provider}/enable', [InternalTomsPaymentOperationsController::class, 'providerEnable']);
    Route::post('/providers/{provider}/disable', [InternalTomsPaymentOperationsController::class, 'providerDisable']);
    Route::get('/methods', [InternalTomsPaymentOperationsController::class, 'methods']);
    Route::post('/methods/{method}/enable', [InternalTomsPaymentOperationsController::class, 'methodEnable']);
    Route::post('/methods/{method}/disable', [InternalTomsPaymentOperationsController::class, 'methodDisable']);
    Route::get('/transactions', [InternalTomsPaymentOperationsController::class, 'transactions']);
    Route::get('/transactions/{reference}', [InternalTomsPaymentOperationsController::class, 'transaction']);
    Route::post('/transactions/{reference}/sync-status', [InternalTomsPaymentOperationsController::class, 'syncTransactionStatus']);
    Route::get('/escrow', [InternalTomsPaymentOperationsController::class, 'escrow']);
    Route::get('/payouts', [InternalTomsPaymentOperationsController::class, 'payouts']);
    Route::post('/payouts/{reference}/retry', [InternalTomsPaymentOperationsController::class, 'retryPayout']);
    Route::get('/refunds', [InternalTomsPaymentOperationsController::class, 'refunds']);
    Route::post('/refunds/{reference}/approve', [InternalTomsPaymentOperationsController::class, 'approveRefund']);
    Route::post('/refunds/{reference}/reject', [InternalTomsPaymentOperationsController::class, 'rejectRefund']);
    Route::post('/refunds/{reference}/retry', [InternalTomsPaymentOperationsController::class, 'retryRefund']);
    Route::post('/refunds/{reference}/complete', [InternalTomsPaymentOperationsController::class, 'completeRefund']);
    Route::get('/webhooks', [InternalTomsPaymentOperationsController::class, 'webhooks']);
    Route::post('/webhooks/{webhook}/review', [InternalTomsPaymentOperationsController::class, 'reviewWebhook']);
    Route::get('/reconciliation', [InternalTomsPaymentOperationsController::class, 'reconciliation']);
    Route::get('/reports', [InternalTomsPaymentOperationsController::class, 'reports']);
});

Route::post('/webhooks/{provider}', [WebhookController::class, 'handle']);
Route::get('/methods', [PaymentController::class, 'methods']);

Route::middleware('auth.internal_or_delegated')->group(function () {
    Route::post('/initiate', [PaymentController::class, 'initiate'])->name('payment.initiate');
});
 
// /status remains buyer-JWT only — this is always a client polling call.
Route::middleware('auth.internal_or_delegated')->group(function () {
    Route::get('/status/{transaction_reference}', [PaymentController::class, 'status']);
});

Route::prefix('rick')->group(function () {
    Route::get('/health', [\App\Http\Controllers\Api\RickConnectorController::class, 'health']);
    Route::post('/query', [\App\Http\Controllers\Api\RickConnectorController::class, 'query']);
});
