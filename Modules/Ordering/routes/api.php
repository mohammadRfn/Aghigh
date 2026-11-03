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

// NoStore wraps auth so 401 answers are private, no-store as well. Rate limiting arrives in Task 7.
Route::middleware([NoStore::class, 'auth:api'])->prefix('orders')->name('ordering.orders.')->group(function (): void {
    Route::post('', [OrderController::class, 'store'])->middleware(RequireIdempotencyKey::class)->name('store');
    Route::get('', [OrderController::class, 'index'])->name('index');
    Route::get('{order}', [OrderController::class, 'show'])->whereUuid('order')->name('show');
    Route::post('{order}/cancel', [OrderController::class, 'cancel'])->whereUuid('order')->name('cancel');
});
