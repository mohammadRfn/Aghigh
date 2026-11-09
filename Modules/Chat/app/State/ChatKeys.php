<?php

declare(strict_types=1);

namespace Modules\Chat\State;

/**
 * Every Redis key the Chat module owns. All live on the `state` connection (noeviction) with the `chat:` prefix
 * (the connection-level REDIS_PREFIX is prepended by phpredis on top). Only Modules\Chat\State touches them
 * (tests/Arch/ChatBoundaryTest.php).
 */
final class ChatKeys
{
    public const PREFIX = 'chat:';

    public const SUPPORT_QUEUE = 'chat:support-queue';        // ZSET member = conversation id, score = enqueue ms

    public const PRESENCE = 'chat:presence';                  // ZSET member = user id, score = last heartbeat ms

    public const PRESENCE_AGENTS = 'chat:presence:agents';    // ZSET, support agents only

    public static function connections(string $userId): string
    {
        return 'chat:conn:'.$userId;                           // ZSET member = socket_id, score = last seen ms
    }

    public static function unread(string $conversationId, string $userId): string
    {
        return 'chat:unread:'.$conversationId.':'.$userId;     // STRING counter
    }

    public static function typing(string $conversationId, string $userId): string
    {
        return 'chat:typing:'.$conversationId.':'.$userId;     // STRING, 5 s
    }

    public static function typingBroadcast(string $conversationId, string $userId): string
    {
        return 'chat:typing-b:'.$conversationId.':'.$userId;   // STRING NX, 3 s broadcast throttle
    }
}
