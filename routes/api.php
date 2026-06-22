<?php

use App\Http\Controllers\Api\EscrowController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.delegated')->group(function () {
    Route::post('/initiate', [PaymentController::class, 'initiate'])->name('payment.initiate');
    Route::get('/status/{transaction_reference}', [PaymentController::class, 'status']);
    Route::get('/orders/{order_reference}/attempts', [PaymentController::class, 'attempts']);
});

Route::middleware('auth.internal')->group(function () {
    Route::get('/escrow/{order_reference}', [EscrowController::class, 'show']);
    Route::post('/escrow/release', [EscrowController::class, 'release']);
});

Route::post('/webhooks/{provider}', [WebhookController::class, 'handle']);
Route::get('/methods', [PaymentController::class, 'methods']);

Route::middleware('auth.internal_or_delegated')->group(function () {
    Route::post('/initiate', [PaymentController::class, 'initiate'])->name('payment.initiate');
});
 
// /status remains buyer-JWT only — this is always a client polling call.
Route::middleware('auth.delegated')->group(function () {
    Route::get('/status/{transaction_reference}', [PaymentController::class, 'status']);
});