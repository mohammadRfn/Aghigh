<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\WebhookController;
use Modules\Shared\Http\Middleware\NoStore;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('payment/ping', fn () => response()->json([
    'module' => 'Payment',
    'status' => 'ok',
]))->name('payment.ping');

// Gateway -> Aghigh. Authenticated by the HMAC signature only, deliberately NOT behind auth:api.
// Rate limiting arrives in Task 7.
Route::post('payments/webhook', WebhookController::class)->middleware(NoStore::class)->name('payment.webhook');
