<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\AdminInventoryController;
use Modules\Shared\Http\Middleware\NoStore;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('inventory/ping', fn () => response()->json([
    'module' => 'Inventory',
    'status' => 'ok',
]))->name('inventory.ping');

// NoStore wraps auth so 401/403 answers are private, no-store as well. Rate limiting arrives in Task 7.
// Task 7: admin limiter (per user, fail closed) after auth.
Route::prefix('admin')->middleware([NoStore::class, 'auth:api', 'throttle.sliding:admin'])->name('inventory.admin.')->group(function (): void {
    Route::get('inventory/{product}', [AdminInventoryController::class, 'show'])->whereUuid('product')->name('stock.show');
    Route::put('inventory/{product}', [AdminInventoryController::class, 'update'])->whereUuid('product')->name('stock.update');
});
