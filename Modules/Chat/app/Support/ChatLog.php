<?php

declare(strict_types=1);

namespace Modules\Chat\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fixed log messages with hashed ids. Message bodies are NEVER passed here (and `body` is a redacted key
 * in RedactSensitiveData as a second line of defence).
 */
final class ChatLog
{
    public static function hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, (string) config('app.key')), 0, 16);
    }

    public static function channelDenied(?string $userId, string $channel, string $reason): void
    {
        self::safe(fn () => Log::channel('security')->warning('chat_channel_denied', [
            'user' => $userId === null ? null : self::hash($userId),
            'channel' => self::hash($channel),
            'reason' => $reason,
        ]));
    }

    /** @param array<string, string|int|bool|null> $extra */
    public static function audit(string $message, string $conversationId, string $actorId, array $extra = []): void
    {
        self::safe(fn () => Log::channel('audit')->info($message, [
            'conversation' => self::hash($conversationId),
            'actor' => self::hash($actorId),
        ] + $extra));
    }

    public static function warning(string $message, ?Throwable $error = null, array $context = []): void
    {
        self::safe(fn () => Log::channel('app')->warning($message, $context + ($error === null ? [] : ['exception' => $error::class])));
    }

    private static function safe(callable $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            // logging must never break a chat request
        }
    }
}
