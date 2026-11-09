<?php

declare(strict_types=1);

namespace Modules\Chat\State;

/**
 * Process-local ChatState for the SQLite Feature suite (CHAT_STATE_DRIVER=memory). Same semantics as the
 * Redis implementation, no atomicity across processes: races are proven in the Concurrency suite on Redis.
 * TTLs are tracked with wall-clock expiries so tests can rely on "missing after expiry" behaviour.
 */
final class InMemoryChatState implements ChatState
{
    /** @var array<string, array<string, int>> sorted sets: key => member => score */
    private array $zsets = [];

    /** @var array<string, array{0: string, 1: float}> strings: key => [value, expires at (s, 0 = never)] */
    private array $strings = [];

    public function flush(): void
    {
        $this->zsets = [];
        $this->strings = [];
    }

    public function queueAdd(string $conversationId, int $scoreMs): bool
    {
        if (isset($this->zsets[ChatKeys::SUPPORT_QUEUE][$conversationId])) {
            return false;
        }
        $this->zsets[ChatKeys::SUPPORT_QUEUE][$conversationId] = $scoreMs;

        return true;
    }

    public function queuePopMin(): ?array
    {
        $set = $this->sorted(ChatKeys::SUPPORT_QUEUE);
        if ($set === []) {
            return null;
        }
        $member = (string) array_key_first($set);
        $score = $set[$member];
        unset($this->zsets[ChatKeys::SUPPORT_QUEUE][$member]);

        return [$member, $score];
    }

    public function queueRemove(string $conversationId): void
    {
        unset($this->zsets[ChatKeys::SUPPORT_QUEUE][$conversationId]);
    }

    public function queueScore(string $conversationId): ?int
    {
        return $this->zsets[ChatKeys::SUPPORT_QUEUE][$conversationId] ?? null;
    }

    public function queueMembers(int $offset, int $count): array
    {
        return array_map('strval', array_slice(array_keys($this->sorted(ChatKeys::SUPPORT_QUEUE)), $offset, max(1, $count)));
    }

    public function queueSize(): int
    {
        return count($this->zsets[ChatKeys::SUPPORT_QUEUE] ?? []);
    }

    public function presenceTouch(string $userId, bool $isAgent, int $nowMs, int $pruneBeforeMs): void
    {
        foreach ($isAgent ? [ChatKeys::PRESENCE, ChatKeys::PRESENCE_AGENTS] : [ChatKeys::PRESENCE] as $key) {
            $this->zsets[$key][$userId] = $nowMs;
            $this->zsets[$key] = array_filter($this->zsets[$key], static fn (int $score): bool => $score >= $pruneBeforeMs);
        }
    }

    public function presenceOnline(int $sinceMs, int $limit): array
    {
        $ids = array_keys(array_filter($this->sorted(ChatKeys::PRESENCE), static fn (int $score): bool => $score >= $sinceMs));

        return array_map('strval', array_slice($ids, 0, max(1, $limit)));
    }

    public function agentsOnline(int $sinceMs): int
    {
        return count(array_filter($this->zsets[ChatKeys::PRESENCE_AGENTS] ?? [], static fn (int $score): bool => $score >= $sinceMs));
    }

    public function unreadIncrementIfPresent(string $conversationId, string $userId): ?int
    {
        $key = ChatKeys::unread($conversationId, $userId);
        $current = $this->get($key);
        if ($current === null) {
            return null;
        }
        $this->strings[$key][0] = (string) ((int) $current + 1);

        return (int) $current + 1;
    }

    public function unreadMany(array $pairs): array
    {
        return array_map(function (array $pair): ?int {
            $value = $this->get(ChatKeys::unread($pair[0], $pair[1]));

            return $value === null ? null : max(0, (int) $value);
        }, $pairs);
    }

    public function unreadSet(string $conversationId, string $userId, int $value, int $ttlSeconds, bool $onlyIfMissing): void
    {
        $key = ChatKeys::unread($conversationId, $userId);
        if ($onlyIfMissing && $this->get($key) !== null) {
            return;
        }
        $this->strings[$key] = [(string) max(0, $value), microtime(true) + max(1, $ttlSeconds)];
    }

    public function unreadForget(string $conversationId, string $userId): void
    {
        unset($this->strings[ChatKeys::unread($conversationId, $userId)]);
    }

    public function typingMark(string $conversationId, string $userId, int $ttlSeconds): void
    {
        $this->strings[ChatKeys::typing($conversationId, $userId)] = ['1', microtime(true) + max(1, $ttlSeconds)];
    }

    public function typingBroadcastSlot(string $conversationId, string $userId, int $ttlSeconds): bool
    {
        $key = ChatKeys::typingBroadcast($conversationId, $userId);
        if ($this->get($key) !== null) {
            return false;
        }
        $this->strings[$key] = ['1', microtime(true) + max(1, $ttlSeconds)];

        return true;
    }

    public function connectionAdmit(string $userId, string $socketId, int $max, int $ttlMs, int $nowMs): array
    {
        $key = ChatKeys::connections($userId);
        $set = array_filter($this->zsets[$key] ?? [], static fn (int $score): bool => $score > $nowMs - $ttlMs);
        if (isset($set[$socketId]) || count($set) < max(1, $max)) {
            $set[$socketId] = $nowMs;
            $this->zsets[$key] = $set;

            return ['admitted' => true, 'count' => count($set)];
        }
        $this->zsets[$key] = $set;

        return ['admitted' => false, 'count' => count($set)];
    }

    public function connectionRefresh(string $userId, string $socketId, int $ttlMs, int $nowMs): bool
    {
        $key = ChatKeys::connections($userId);
        if (! isset($this->zsets[$key][$socketId])) {
            return false;
        }
        $this->zsets[$key][$socketId] = $nowMs;

        return true;
    }

    public function connectionCount(string $userId, int $ttlMs, int $nowMs): int
    {
        return count(array_filter($this->zsets[ChatKeys::connections($userId)] ?? [], static fn (int $score): bool => $score > $nowMs - $ttlMs));
    }

    /** @return array<string, int> */
    private function sorted(string $key): array
    {
        $set = $this->zsets[$key] ?? [];
        uksort($set, static fn (int|string $a, int|string $b): int => [$set[$a], (string) $a] <=> [$set[$b], (string) $b]);

        return $set;
    }

    private function get(string $key): ?string
    {
        if (! isset($this->strings[$key])) {
            return null;
        }
        [$value, $expires] = $this->strings[$key];
        if ($expires > 0 && $expires < microtime(true)) {
            unset($this->strings[$key]);

            return null;
        }

        return $value;
    }
}
