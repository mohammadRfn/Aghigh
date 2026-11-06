<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\AuthController;
use Modules\Identity\Http\Controllers\UserController;
use Modules\Identity\Http\Middleware\RequireOrigin;

Route::get('identity/ping', fn () => response()->json(['module' => 'Identity', 'status' => 'ok']))->name('identity.ping');

// Limiters are named in config/rate-limits.php (Task 7). They are per IP (login: per IP + email tag) and run
// before any credential check; account lockout (identity.lockout.*) is a separate per-account mechanism.
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle.sliding:auth-register')->name('register');
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verify'])->whereUuid('id')->middleware(['signed', 'throttle.sliding:auth-register'])->name('verify');
    Route::post('email/resend', [AuthController::class, 'resend'])->middleware('throttle.sliding:auth-email-resend')->name('resend');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle.sliding:auth-login')->name('login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware([RequireOrigin::class, 'throttle.sliding:auth-refresh'])->name('refresh');
    Route::post('logout', [AuthController::class, 'logout'])->middleware([RequireOrigin::class, 'auth:api'])->name('logout');
    Route::post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle.sliding:auth-forgot-password')->name('forgot');
    Route::post('reset-password', [AuthController::class, 'reset'])->middleware('throttle.sliding:auth-forgot-password')->name('reset');
});

Route::middleware('auth:api')->group(function (): void {
    Route::get('me', [UserController::class, 'me'])->name('me');
    Route::get('users/{user}', [UserController::class, 'show'])->whereUuid('user')->name('users.show');
    Route::patch('users/{user}/role', [UserController::class, 'role'])->whereUuid('user')->middleware('throttle.sliding:admin')->name('users.role');
});
