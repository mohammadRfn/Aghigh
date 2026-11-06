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
// Task 7: generous per-IP limiter (webhook), fail open: the HMAC signature is the real gate.
Route::post('payments/webhook', WebhookController::class)->middleware([NoStore::class, 'throttle.sliding:webhook'])->name('payment.webhook');
