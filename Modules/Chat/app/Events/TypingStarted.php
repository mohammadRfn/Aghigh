<?php

declare(strict_types=1);

namespace Modules\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Modules\Chat\Support\ChatTime;
use Modules\Shared\Queue\Queues;
use Throwable;

/** Typing indicator: throttled to one per 3 s per (conversation, user), tries 1 (stale typing is worthless), never persisted. */
final class TypingStarted implements ShouldBroadcast
{
    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $userId,
        public readonly string $role,
        public readonly int $expiresInSeconds,
    ) {}

    public function broadcastQueue(): string
    {
        return Queues::BROADCASTS;
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'typing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'role' => $this->role,
            'expires_in' => $this->expiresInSeconds,
            'server_time' => ChatTime::wire(ChatTime::now()),
        ];
    }

    public function failed(?Throwable $error = null): void
    {
        // Dropped on purpose: a late typing indicator is worse than none. No log noise.
    }
}
