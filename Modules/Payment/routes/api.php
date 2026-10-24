<?php

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('payment/ping', fn () => response()->json([
    'module' => 'Payment',
    'status' => 'ok',
]))->name('payment.ping');
