<?php

declare(strict_types=1);

namespace Modules\Chat\State;

use Illuminate\Contracts\Redis\Factory;
use Redis;
use RuntimeException;

/**
 * Chat state on the `state` Redis connection (redis-queue db 2, noeviction). Talks to the phpredis client
 * directly: phpredis applies the connection prefix (REDIS_PREFIX) to normal commands and to the KEYS of
 * EVAL/EVALSHA, so the Lua scripts below see the same key names as the plain commands.
 * The Lua scripts are owned by Chat (not by Shared\RateLimiting, which owns only limiter scripts).
 */
final class RedisChatState implements ChatState
{
    /** KEYS[1] counter. Returns the new value, or -1 when the key does not exist (DB is the truth then). */
    public const INCR_IF_PRESENT = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 1 then
            return redis.call('INCR', KEYS[1])
        end
        return -1
        LUA;

    /**
     * Per-user connection cap. KEYS[1] ZSET socket_id -> last seen ms.
     * ARGV[1] max, ARGV[2] ttl ms, ARGV[3] socket id, ARGV[4] now ms. Returns {admitted 0|1, count}.
     * Prune, check and add run as one script, so two parallel channel auths cannot both take the last slot.
     */
    public const CONNECTION_ADMIT = <<<'LUA'
        local key = KEYS[1]
        local max = tonumber(ARGV[1])
        local ttl = tonumber(ARGV[2])
        local socket = ARGV[3]
        local now = tonumber(ARGV[4])
        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - ttl)
        if redis.call('ZSCORE', key, socket) then
            redis.call('ZADD', key, now, socket)
            redis.call('PEXPIRE', key, ttl)
            return {1, redis.call('ZCARD', key)}
        end
        local count = redis.call('ZCARD', key)
        if count < max then
            redis.call('ZADD', key, now, socket)
            redis.call('PEXPIRE', key, ttl)
            return {1, count + 1}
        end
        return {0, count}
        LUA;

    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection = 'state',
    ) {}

    public function queueAdd(string $conversationId, int $scoreMs): bool
    {
        return (int) $this->client()->zAdd(ChatKeys::SUPPORT_QUEUE, ['NX'], $scoreMs, $conversationId) === 1;
    }

    public function queuePopMin(): ?array
    {
        $popped = $this->client()->zPopMin(ChatKeys::SUPPORT_QUEUE, 1);
        if (! is_array($popped) || $popped === []) {
            return null;
        }
        foreach ($popped as $member => $score) {
            return [(string) $member, (int) $score];
        }

        return null;
    }

    public function queueRemove(string $conversationId): void
    {
        $this->client()->zRem(ChatKeys::SUPPORT_QUEUE, $conversationId);
    }

    public function queueScore(string $conversationId): ?int
    {
        $score = $this->client()->zScore(ChatKeys::SUPPORT_QUEUE, $conversationId);

        return $score === false || $score === null ? null : (int) $score;
    }

    public function queueMembers(int $offset, int $count): array
    {
        $members = $this->client()->zRange(ChatKeys::SUPPORT_QUEUE, $offset, $offset + max(1, $count) - 1);

        return is_array($members) ? array_values(array_map('strval', $members)) : [];
    }

    public function queueSize(): int
    {
        return (int) $this->client()->zCard(ChatKeys::SUPPORT_QUEUE);
    }

    public function presenceTouch(string $userId, bool $isAgent, int $nowMs, int $pruneBeforeMs): void
    {
        $pipe = $this->client()->multi(Redis::PIPELINE);
        $pipe->zAdd(ChatKeys::PRESENCE, $nowMs, $userId);
        $pipe->zRemRangeByScore(ChatKeys::PRESENCE, '-inf', '('.$pruneBeforeMs);
        if ($isAgent) {
            $pipe->zAdd(ChatKeys::PRESENCE_AGENTS, $nowMs, $userId);
            $pipe->zRemRangeByScore(ChatKeys::PRESENCE_AGENTS, '-inf', '('.$pruneBeforeMs);
        }
        $pipe->exec();
    }

    public function presenceOnline(int $sinceMs, int $limit): array
    {
        $ids = $this->client()->zRangeByScore(ChatKeys::PRESENCE, (string) $sinceMs, '+inf', ['limit' => [0, max(1, $limit)]]);

        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
    }

    public function agentsOnline(int $sinceMs): int
    {
        return (int) $this->client()->zCount(ChatKeys::PRESENCE_AGENTS, (string) $sinceMs, '+inf');
    }

    public function unreadIncrementIfPresent(string $conversationId, string $userId): ?int
    {
        $reply = $this->script(self::INCR_IF_PRESENT, [ChatKeys::unread($conversationId, $userId)], []);
        $value = is_array($reply) ? (int) ($reply[0] ?? -1) : (int) $reply;

        return $value < 0 ? null : $value;
    }

    public function unreadMany(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $values = $this->client()->mGet(array_map(static fn (array $p): string => ChatKeys::unread($p[0], $p[1]), $pairs));
        $out = [];
        foreach (array_values(is_array($values) ? $values : []) as $value) {
            $out[] = $value === false || $value === null || ! is_numeric($value) ? null : max(0, (int) $value);
        }

        return array_pad($out, count($pairs), null);
    }

    public function unreadSet(string $conversationId, string $userId, int $value, int $ttlSeconds, bool $onlyIfMissing): void
    {
        $options = $onlyIfMissing ? ['nx', 'ex' => max(1, $ttlSeconds)] : ['ex' => max(1, $ttlSeconds)];
        $this->client()->set(ChatKeys::unread($conversationId, $userId), (string) max(0, $value), $options);
    }

    public function unreadForget(string $conversationId, string $userId): void
    {
        $this->client()->del(ChatKeys::unread($conversationId, $userId));
    }

    public function typingMark(string $conversationId, string $userId, int $ttlSeconds): void
    {
        $this->client()->set(ChatKeys::typing($conversationId, $userId), '1', ['ex' => max(1, $ttlSeconds)]);
    }

    public function typingBroadcastSlot(string $conversationId, string $userId, int $ttlSeconds): bool
    {
        return $this->client()->set(ChatKeys::typingBroadcast($conversationId, $userId), '1', ['nx', 'ex' => max(1, $ttlSeconds)]) === true;
    }

    public function connectionAdmit(string $userId, string $socketId, int $max, int $ttlMs, int $nowMs): array
    {
        $reply = $this->script(self::CONNECTION_ADMIT, [ChatKeys::connections($userId)], [(string) max(1, $max), (string) max(1000, $ttlMs), $socketId, (string) $nowMs]);
        if (! is_array($reply)) {
            throw new RuntimeException('chat connection script returned '.get_debug_type($reply));
        }

        return ['admitted' => (int) ($reply[0] ?? 0) === 1, 'count' => (int) ($reply[1] ?? 0)];
    }

    public function connectionRefresh(string $userId, string $socketId, int $ttlMs, int $nowMs): bool
    {
        $client = $this->client();
        $key = ChatKeys::connections($userId);
        $changed = (int) $client->zAdd($key, ['XX', 'CH'], $nowMs, $socketId) === 1;
        if ($changed) {
            $client->pExpire($key, max(1000, $ttlMs));
        }

        return $changed;
    }

    public function connectionCount(string $userId, int $ttlMs, int $nowMs): int
    {
        return (int) $this->client()->zCount(ChatKeys::connections($userId), '('.($nowMs - $ttlMs), '+inf');
    }

    private function client(): Redis
    {
        $client = $this->redis->connection($this->connection)->client();
        if (! $client instanceof Redis) {
            throw new RuntimeException('Chat state requires the phpredis client (REDIS_CLIENT=phpredis).');
        }

        return $client;
    }

    /**
     * EVALSHA with an EVAL fallback on NOSCRIPT.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function script(string $script, array $keys, array $arguments): mixed
    {
        $client = $this->client();
        $params = [...$keys, ...$arguments];
        $result = $client->evalSha(sha1($script), $params, count($keys));
        if ($result === false) {
            $error = (string) $client->getLastError();
            $client->clearLastError();
            if (! str_contains($error, 'NOSCRIPT')) {
                throw new RuntimeException('chat redis script failed: '.($error !== '' ? $error : 'unknown error'));
            }
            $result = $client->eval($script, $params, count($keys));
            if ($result === false && ($error = (string) $client->getLastError()) !== '') {
                $client->clearLastError();
                throw new RuntimeException('chat redis script failed: '.$error);
            }
        }

        return $result;
    }
}
