<?php

declare(strict_types=1);

namespace Modules\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Http\Resources\ChatPresenter;
use Modules\Chat\Support\ChatLog;
use Modules\Chat\Support\ChatTime;
use Modules\Shared\Queue\Queues;
use Throwable;

/** Status / assignment change on `private-conversation.{id}` (claimed, released, closed). Ids only. */
final class ConversationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public int $tries = 3;

    public int $timeout = 20;

    /** @var list<int> */
    public array $backoff = [1, 3, 10];

    public bool $afterCommit = true;

    public function __construct(public readonly string $conversationId) {}

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
        return 'conversation.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $row = DB::table('conversations')->where('id', $this->conversationId)->first();
        $data = is_object($row) ? ChatPresenter::conversation($row) : ['id' => $this->conversationId];

        return $data + ['server_time' => ChatTime::wire(ChatTime::now())];
    }

    public function failed(?Throwable $error = null): void
    {
        ChatLog::warning('chat_broadcast_failed', $error, ['event' => 'conversation.updated', 'conversation' => ChatLog::hash($this->conversationId)]);
    }
}
