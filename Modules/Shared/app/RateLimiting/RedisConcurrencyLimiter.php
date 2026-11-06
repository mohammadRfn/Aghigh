<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Illuminate\Contracts\Redis\Factory;
use Modules\Shared\Contracts\ConcurrencyLimiter;
use Throwable;

/**
 * Lua-backed semaphore on the `state` Redis: sorted set of in-flight members scored by their expiry, so a
 * worker that dies mid-request (OOM, SIGKILL, Octane restart) frees its slot once ttl passes.
 * Release = ZREM of the caller's own member only: idempotent, never touches another request's slot.
 */
final class RedisConcurrencyLimiter implements ConcurrencyLimiter
{
    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection = 'state',
    ) {}

    public function acquire(string $key, string $member, int $max, int $ttlMs, ?int $nowMs = null): bool
    {
        $reply = LuaScripts::run(
            $this->connection(),
            LuaScripts::SEMAPHORE_ACQUIRE,
            self::key($key),
            [(string) max(1, $max), (string) max(1, $ttlMs), $member, $nowMs === null ? '' : (string) $nowMs],
        );

        return (int) ($reply[0] ?? 0) === 1;
    }

    public function release(string $key, string $member): void
    {
        try {
            $this->connection()->zrem(self::key($key), $member);
        } catch (Throwable $error) {
            // The slot expires on its own; a failed release must never turn a successful response into an error.
            throw LimiterBackendUnavailable::from($error);
        }
    }

    public function inFlight(string $key, ?int $nowMs = null): int
    {
        try {
            $connection = $this->connection();
            if ($nowMs === null) {
                [$seconds, $micro] = $connection->time();
                $nowMs = (int) $seconds * 1000 + intdiv((int) $micro, 1000);
            }

            return (int) $connection->zcount(self::key($key), (string) ($nowMs + 1), '+inf');
        } catch (Throwable $error) {
            throw LimiterBackendUnavailable::from($error);
        }
    }

    public static function key(string $key): string
    {
        return LuaScripts::KEY_PREFIX.'cc:'.$key;
    }

    private function connection(): \Illuminate\Redis\Connections\Connection
    {
        try {
            return $this->redis->connection($this->connection);
        } catch (Throwable $error) {
            throw LimiterBackendUnavailable::from($error);
        }
    }
}
