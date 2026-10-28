<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\AuthController;
use Modules\Identity\Http\Controllers\UserController;
use Modules\Identity\Http\Middleware\RequireOrigin;

Route::get('identity/ping', fn () => response()->json(['module' => 'Identity', 'status' => 'ok']))->name('identity.ping');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verify'])->whereUuid('id')->middleware(['signed', 'throttle:register'])->name('verify');
    Route::post('email/resend', [AuthController::class, 'resend'])->middleware('throttle:forgot-password')->name('resend');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware(RequireOrigin::class)->name('refresh');
    Route::post('logout', [AuthController::class, 'logout'])->middleware([RequireOrigin::class, 'auth:api'])->name('logout');
    Route::post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:forgot-password')->name('forgot');
    Route::post('reset-password', [AuthController::class, 'reset'])->middleware('throttle:forgot-password')->name('reset');
});

Route::middleware('auth:api')->group(function (): void {
    Route::get('me', [UserController::class, 'me'])->name('me');
    Route::get('users/{user}', [UserController::class, 'show'])->whereUuid('user')->name('users.show');
    Route::patch('users/{user}/role', [UserController::class, 'role'])->whereUuid('user')->name('users.role');
});
