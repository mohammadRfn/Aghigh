<?php

declare(strict_types=1);

namespace Modules\Chat\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Services\UnreadCounters;
use Modules\Chat\Support\ChatTime;
use Modules\Chat\Support\Sql;
use Modules\Identity\Contracts\CurrentUser;

/** POST /conversations/{id}/read: conversation_reads is the truth; the marker only moves forward, capped at last_seq. */
final class MarkRead
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly UnreadCounters $unread,
    ) {}

    /** @return array{conversation_id: string, last_read_seq: int, last_seq: int, unread: int} */
    public function __invoke(CurrentUser $actor, string $conversationId, ?int $seq): array
    {
        $conversation = $this->policy->find($actor, $conversationId) ?? throw ChatProblem::notFound();
        $id = (string) $conversation->id;
        $lastSeq = (int) $conversation->last_seq;
        $target = max(0, min($seq ?? $lastSeq, $lastSeq));

        $greatest = Sql::greatest('conversation_reads.last_read_seq', 'excluded.last_read_seq');
        DB::statement(
            "INSERT INTO conversation_reads (conversation_id, user_id, last_read_seq, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (conversation_id, user_id) DO UPDATE SET last_read_seq = {$greatest}, updated_at = excluded.updated_at",
            [$id, $actor->id, $target, ChatTime::db(ChatTime::now())],
        );
        $stored = (int) DB::table('conversation_reads')->where('conversation_id', $id)->where('user_id', $actor->id)->value('last_read_seq');
        $this->unread->forget($id, $actor->id);

        return ['conversation_id' => $id, 'last_read_seq' => $stored, 'last_seq' => $lastSeq, 'unread' => max(0, $lastSeq - $stored)];
    }
}
