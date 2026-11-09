<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\DB;
use Modules\Chat\State\ChatState;
use Modules\Chat\Support\ChatLog;
use Throwable;

/**
 * Unread counters per (conversation, user). The truth is the database: unread = last_seq - last_read_seq
 * (the sender's own read marker advances with every message they send). Redis holds a cache:
 * - a new message INCRs the other party's counter only if the key exists (a missing key = "ask the DB"),
 * - a read DELetes it,
 * - the list endpoint backfills misses from the DB with SET NX EX (unread_ttl_seconds bounds any drift).
 * Flushing Redis therefore never changes the numbers clients see; chat:rebuild-unread warms the cache.
 */
final class UnreadCounters
{
    public function __construct(private readonly ChatState $state) {}

    public function incrementFor(string $conversationId, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }
        try {
            $this->state->unreadIncrementIfPresent($conversationId, $userId);
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'unread_incr']);
        }
    }

    public function forget(string $conversationId, string $userId): void
    {
        try {
            $this->state->unreadForget($conversationId, $userId);
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'unread_forget']);
        }
    }

    /**
     * @param  list<object>  $conversations  rows with id and last_seq
     * @return array<string, int> conversation id => unread for $userId
     */
    public function forUser(string $userId, array $conversations): array
    {
        if ($conversations === []) {
            return [];
        }
        $ids = array_map(static fn (object $c): string => (string) $c->id, $conversations);
        try {
            $cached = $this->state->unreadMany(array_map(static fn (string $id): array => [$id, $userId], $ids));
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'unread_get']);
            $cached = array_fill(0, count($ids), null);
        }

        $out = [];
        $misses = [];
        foreach ($conversations as $index => $conversation) {
            $value = $cached[$index] ?? null;
            if ($value === null) {
                $misses[] = $conversation;
            } else {
                $out[(string) $conversation->id] = $value;
            }
        }
        if ($misses !== []) {
            $fromDb = $this->fromDatabase($userId, $misses);
            $ttl = (int) config('chat.unread_ttl_seconds', 300);
            foreach ($fromDb as $id => $value) {
                $out[$id] = $value;
                try {
                    $this->state->unreadSet($id, $userId, $value, $ttl, true);
                } catch (Throwable) {
                    break; // backend down: serve DB values, skip backfill
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<object>  $conversations
     * @return array<string, int>
     */
    public function fromDatabase(string $userId, array $conversations): array
    {
        $ids = array_map(static fn (object $c): string => (string) $c->id, $conversations);
        $reads = DB::table('conversation_reads')->where('user_id', $userId)->whereIn('conversation_id', $ids)
            ->pluck('last_read_seq', 'conversation_id')->all();
        $out = [];
        foreach ($conversations as $conversation) {
            $out[(string) $conversation->id] = max(0, (int) $conversation->last_seq - (int) ($reads[(string) $conversation->id] ?? 0));
        }

        return $out;
    }

    /** Recompute every participant's counter of non-closed conversations from the DB. Returns keys written. */
    public function rebuild(int $chunk = 500): int
    {
        $written = 0;
        $ttl = (int) config('chat.unread_ttl_seconds', 300);
        DB::table('conversations')->where('status', '<>', 'closed')->select(['id', 'customer_id', 'assigned_agent_id', 'last_seq'])
            ->orderBy('id')->chunkById(max(1, $chunk), function ($rows) use (&$written, $ttl): void {
                $byUser = [];
                foreach ($rows as $row) {
                    $byUser[(string) $row->customer_id][] = $row;
                    if ($row->assigned_agent_id !== null) {
                        $byUser[(string) $row->assigned_agent_id][] = $row;
                    }
                }
                foreach ($byUser as $userId => $conversations) {
                    foreach ($this->fromDatabase((string) $userId, $conversations) as $id => $value) {
                        $this->state->unreadSet($id, (string) $userId, $value, $ttl, false);
                        $written++;
                    }
                }
            });

        return $written;
    }
}
