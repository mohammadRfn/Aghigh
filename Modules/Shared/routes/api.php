<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('shared/ping', fn () => response()->json([
    'module' => 'Shared',
    'status' => 'ok',
]))->name('shared.ping');
