<?php

declare(strict_types=1);

namespace Modules\Chat\State;

/**
 * Ephemeral / derived chat state. Redis implementation in production and in the Concurrency suite,
 * in-memory implementation for the SQLite Feature suite (CHAT_STATE_DRIVER=memory).
 * Nothing here is the source of truth: Postgres is. Every structure can be rebuilt from the database.
 * Implementations throw on backend failure; callers decide fail-open or fail-closed.
 */
interface ChatState
{
    /** ZADD NX. Returns true when the member was added. */
    public function queueAdd(string $conversationId, int $scoreMs): bool;

    /** Atomic ZPOPMIN. @return array{0: string, 1: int}|null [conversation id, original score] */
    public function queuePopMin(): ?array;

    public function queueRemove(string $conversationId): void;

    public function queueScore(string $conversationId): ?int;

    /** @return list<string> members by rank, ascending score */
    public function queueMembers(int $offset, int $count): array;

    public function queueSize(): int;

    public function presenceTouch(string $userId, bool $isAgent, int $nowMs, int $pruneBeforeMs): void;

    /** @return list<string> user ids seen at or after $sinceMs */
    public function presenceOnline(int $sinceMs, int $limit): array;

    public function agentsOnline(int $sinceMs): int;

    /** INCR only when the key already exists (a missing key means "compute from the DB"). */
    public function unreadIncrementIfPresent(string $conversationId, string $userId): ?int;

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  [conversation id, user id]
     * @return list<int|null>
     */
    public function unreadMany(array $pairs): array;

    public function unreadSet(string $conversationId, string $userId, int $value, int $ttlSeconds, bool $onlyIfMissing): void;

    public function unreadForget(string $conversationId, string $userId): void;

    public function typingMark(string $conversationId, string $userId, int $ttlSeconds): void;

    /** SET NX EX: true at most once per $ttlSeconds per (conversation, user). */
    public function typingBroadcastSlot(string $conversationId, string $userId, int $ttlSeconds): bool;

    /**
     * Atomic prune + check + add of a socket id in the per-user set.
     *
     * @return array{admitted: bool, count: int}
     */
    public function connectionAdmit(string $userId, string $socketId, int $max, int $ttlMs, int $nowMs): array;

    /** ZADD XX: refresh the score of a socket that already passed channel auth. */
    public function connectionRefresh(string $userId, string $socketId, int $ttlMs, int $nowMs): bool;

    public function connectionCount(string $userId, int $ttlMs, int $nowMs): int;
}
