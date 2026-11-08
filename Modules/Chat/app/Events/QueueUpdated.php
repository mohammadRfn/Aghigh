<?php

declare(strict_types=1);

namespace Modules\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Modules\Chat\Support\ChatLog;
use Modules\Chat\Support\ChatTime;
use Modules\Shared\Queue\Queues;
use Throwable;

/** `private-agents.queue`: a conversation entered the queue (queued / released), was claimed or was closed. */
final class QueueUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public const QUEUED = 'queued';

    public const CLAIMED = 'claimed';

    public const RELEASED = 'released';

    public const CLOSED = 'closed';

    public int $tries = 3;

    public int $timeout = 20;

    /** @var list<int> */
    public array $backoff = [1, 3, 10];

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $action,
    ) {}

    public function broadcastQueue(): string
    {
        return Queues::BROADCASTS;
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('agents.queue')];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'action' => $this->action, 'server_time' => ChatTime::wire(ChatTime::now())];
    }

    public function failed(?Throwable $error = null): void
    {
        ChatLog::warning('chat_broadcast_failed', $error, ['event' => 'queue.updated', 'conversation' => ChatLog::hash($this->conversationId)]);
    }
}
