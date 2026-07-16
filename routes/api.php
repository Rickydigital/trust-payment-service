<?php

use App\Http\Controllers\Api\EscrowController;
use App\Http\Controllers\Api\Internal\TomsCrmController as InternalTomsCrmController;
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
});

Route::get('/internal/toms/crm/search', [InternalTomsCrmController::class, 'search']);
Route::get('/internal/toms/crm/profile', [InternalTomsCrmController::class, 'profile']);
Route::get('/internal/toms/crm/work-items', [InternalTomsCrmController::class, 'workItems']);
Route::post('/internal/toms/crm/work-items/{type}/{id}/resolve', [InternalTomsCrmController::class, 'resolveWorkItem']);

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
