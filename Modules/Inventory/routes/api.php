<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('inventory/ping', fn () => response()->json([
    'module' => 'Inventory',
    'status' => 'ok',
]))->name('inventory.ping');
