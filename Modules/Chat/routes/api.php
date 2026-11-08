<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ConversationController;
use Modules\Chat\Http\Controllers\MessageController;
use Modules\Chat\Http\Controllers\PresenceController;
use Modules\Chat\Http\Controllers\SupportQueueController;
use Modules\Shared\Http\Middleware\NoStore;

Route::get('chat/ping', fn () => response()->json([
    'module' => 'Chat',
    'status' => 'ok',
]))->name('chat.ping');

/*
 | Task 8. NoStore wraps auth:api (private data, even on 401). Per-user limiters run AFTER auth:api
 | (order: auth:api -> throttle.sliding:{name}); names and values live in config/rate-limits.php.
 | Unknown, malformed and foreign conversation ids all answer the same 404 (no existence leak).
 */
Route::middleware([NoStore::class, 'auth:api'])->name('chat.')->group(function (): void {
    Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');

    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware('throttle.sliding:chat-messages')->whereUuid('conversation')->name('messages.store');
    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])
        ->whereUuid('conversation')->name('messages.index');
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])
        ->middleware('throttle.sliding:chat-read')->whereUuid('conversation')->name('conversations.read');
    Route::post('conversations/{conversation}/typing', [ConversationController::class, 'typing'])
        ->middleware('throttle.sliding:chat-typing')->whereUuid('conversation')->name('conversations.typing');
    Route::post('conversations/{conversation}/close', [ConversationController::class, 'close'])
        ->whereUuid('conversation')->name('conversations.close');
    Route::post('conversations/{conversation}/release', [ConversationController::class, 'release'])
        ->whereUuid('conversation')->name('conversations.release');

    Route::post('support/queue/claim', [SupportQueueController::class, 'claim'])
        ->middleware('throttle.sliding:chat-claim')->name('support.claim');

    Route::post('presence/heartbeat', [PresenceController::class, 'heartbeat'])
        ->middleware('throttle.sliding:chat-heartbeat')->name('presence.heartbeat');
    Route::get('presence', [PresenceController::class, 'show'])
        ->middleware('throttle.sliding:chat-heartbeat')->name('presence.show');
});
