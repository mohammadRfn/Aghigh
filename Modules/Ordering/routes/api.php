<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ordering\Http\Controllers\OrderController;
use Modules\Ordering\Http\Middleware\RequireIdempotencyKey;
use Modules\Shared\Http\Middleware\NoStore;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('ordering/ping', fn () => response()->json([
    'module' => 'Ordering',
    'status' => 'ok',
]))->name('ordering.ping');

// Middleware order (Task 7), asserted by Modules/Ordering/tests/Feature/OrderRateLimitTest.php:
//   throttle.sliding:orders-ip (pre-auth, per IP) -> auth:api -> throttle.sliding:orders (per user, sliding log)
//   -> concurrency.cap:orders -> RequireIdempotencyKey. A 429 is thrown before the key middleware runs.
// NoStore wraps auth so 401 answers are private, no-store as well.
Route::middleware([NoStore::class, 'throttle.sliding:orders-ip', 'auth:api', 'throttle.sliding:orders'])
    ->prefix('orders')->name('ordering.orders.')->group(function (): void {
        Route::post('', [OrderController::class, 'store'])
            ->middleware(['concurrency.cap:orders', RequireIdempotencyKey::class])->name('store');
        Route::get('', [OrderController::class, 'index'])->name('index');
        Route::get('{order}', [OrderController::class, 'show'])->whereUuid('order')->name('show');
        Route::post('{order}/cancel', [OrderController::class, 'cancel'])->whereUuid('order')->name('cancel');
    });
