<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\State\ChatState;
use Modules\Chat\Support\ChatLog;
use Modules\Chat\Support\ChatTime;
use Throwable;

/**
 * Redis sorted set `chat:support-queue` (score = enqueue time in ms = conversation created_at). It is an index
 * over `conversations WHERE status = 'waiting'`, never the truth: the conditional UPDATE in the claim is the guard,
 * and sweep() repairs any divergence (missed enqueue, crash between ZPOPMIN and the UPDATE, flushed Redis).
 */
final class SupportQueue
{
    public function __construct(private readonly ChatState $state) {}

    /** Best effort after commit; a failure is repaired by the scheduled sweep. */
    public function enqueue(string $conversationId, string $createdAt): void
    {
        try {
            $this->state->queueAdd($conversationId, ChatTime::ms($createdAt));
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'queue_add']);
        }
    }

    public function remove(string $conversationId): void
    {
        try {
            $this->state->queueRemove($conversationId);
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'queue_remove']);
        }
    }

    /**
     * 1) ZADD NX every waiting conversation missing from Redis (score = created_at ms, so order is preserved);
     * 2) ZREM every member that is no longer waiting (claimed, closed, deleted).
     * A conversation claimed between step 1's read and its ZADD may be re-added; step 2 or the next claim
     * (UPDATE touches 0 rows, entry is dropped) cleans it up.
     *
     * @return array{added: int, removed: int, size: int}
     */
    public function sweep(?int $chunk = null): array
    {
        $chunk = max(1, $chunk ?? (int) config('chat.sweep_chunk', 500));
        $added = 0;
        DB::table('conversations')->where('status', ConversationStatus::Waiting->value)->select(['id', 'created_at'])
            ->orderBy('id')->chunkById($chunk, function ($rows) use (&$added): void {
                foreach ($rows as $row) {
                    if ($this->state->queueAdd((string) $row->id, ChatTime::ms((string) $row->created_at))) {
                        $added++;
                    }
                }
            });

        $removed = 0;
        $offset = 0;
        while (($members = $this->state->queueMembers($offset, $chunk)) !== []) {
            $waiting = DB::table('conversations')->whereIn('id', $members)->where('status', ConversationStatus::Waiting->value)
                ->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
            $stale = array_values(array_diff($members, $waiting));
            foreach ($stale as $member) {
                $this->state->queueRemove($member);
                $removed++;
            }
            $offset += count($members) - count($stale);
        }

        return ['added' => $added, 'removed' => $removed, 'size' => $this->state->queueSize()];
    }
}
