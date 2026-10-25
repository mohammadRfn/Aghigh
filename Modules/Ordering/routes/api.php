<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('ordering/ping', fn () => response()->json([
    'module' => 'Ordering',
    'status' => 'ok',
]))->name('ordering.ping');
