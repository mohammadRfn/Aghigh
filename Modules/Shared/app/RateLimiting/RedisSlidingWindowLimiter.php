<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;
use Modules\Shared\Contracts\SlidingWindowLimiter;
use Throwable;

/**
 * Sliding log on the `state` Redis: one sorted set per key, score = Redis TIME in ms, member = UUIDv7
 * (unique even for simultaneous attempts). The whole decision is one Lua script, so N parallel callers
 * against a limit of L get exactly L allowances (tests/Concurrency/SlidingWindowLimiterTest.php).
 */
final class RedisSlidingWindowLimiter implements SlidingWindowLimiter
{
    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection = 'state',
    ) {}

    public function attempt(string $key, int $limit, int $windowSeconds, ?int $nowMs = null): RateLimitResult
    {
        $limit = max(1, $limit);
        $windowMs = max(1, $windowSeconds) * 1000;

        $reply = LuaScripts::run(
            $this->connection(),
            LuaScripts::SLIDING_WINDOW,
            self::key($key),
            [(string) $limit, (string) $windowMs, (string) Str::uuid7(), $nowMs === null ? '' : (string) $nowMs],
        );

        return new RateLimitResult(
            allowed: (int) ($reply[0] ?? 0) === 1,
            limit: $limit,
            remaining: (int) ($reply[1] ?? 0),
            retryAfterMs: (int) ($reply[2] ?? 0),
            resetAtMs: (int) ($reply[3] ?? 0),
            nowMs: (int) ($reply[4] ?? 0),
        );
    }

    public function clear(string $key): void
    {
        try {
            $this->connection()->del(self::key($key));
        } catch (Throwable $error) {
            throw LimiterBackendUnavailable::from($error);
        }
    }

    public static function key(string $key): string
    {
        return LuaScripts::KEY_PREFIX.'sw:'.$key;
    }

    private function connection(): Connection
    {
        try {
            return $this->redis->connection($this->connection);
        } catch (Throwable $error) {
            throw LimiterBackendUnavailable::from($error);
        }
    }
}
