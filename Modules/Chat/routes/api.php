<?php

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('chat/ping', fn () => response()->json([
    'module' => 'Chat',
    'status' => 'ok',
]))->name('chat.ping');
