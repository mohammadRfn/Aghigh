<?php

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('notification/ping', fn () => response()->json([
    'module' => 'Notification',
    'status' => 'ok',
]))->name('notification.ping');
