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

/**
 * Notifies `private-conversation.{id}` about a message that is ALREADY persisted. Carries ids only;
 * broadcastWith() loads the committed row in the broadcast worker. Best effort (no outbox): a lost or
 * failed broadcast is recovered by the client's gap-filling (`GET .../messages?after_seq=N`), and two
 * workers may deliver out of order, so clients order and dedupe by (conversation_id, seq).
 */
final class MessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public int $tries = 3;

    /** Below the broadcasts supervisor timeout (30 s), which is below retry_after (REDIS_QUEUE_RETRY_AFTER). */
    public int $timeout = 20;

    /** @var list<int> */
    public array $backoff = [1, 3, 10];

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
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
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $row = DB::table('messages')->where('id', $this->messageId)->first();
        if (! is_object($row)) {
            return ['conversation_id' => $this->conversationId, 'message_id' => $this->messageId, 'server_time' => ChatTime::wire(ChatTime::now())];
        }

        return ChatPresenter::message($row) + ['server_time' => ChatTime::wire(ChatTime::now())];
    }

    public function failed(?Throwable $error = null): void
    {
        ChatLog::warning('chat_broadcast_failed', $error, ['event' => 'message.sent', 'conversation' => ChatLog::hash($this->conversationId)]);
    }
}
