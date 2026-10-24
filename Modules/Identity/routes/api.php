<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('identity/ping', fn () => response()->json([
    'module' => 'Identity',
    'status' => 'ok',
]))->name('identity.ping');
