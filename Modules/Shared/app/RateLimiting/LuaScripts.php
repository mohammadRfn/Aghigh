<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Throwable;

/**
 * The ONLY place that owns limiter Lua and limiter keys (tests/Arch/RateLimitingBoundaryTest.php).
 * Scripts are loaded by EVALSHA; a NOSCRIPT answer (fresh Redis, SCRIPT FLUSH, failover) falls back to
 * EVAL, which also re-caches the script. No per-process state: the SHA is a pure function of the source,
 * so this is Octane-safe and needs no "loaded" flag.
 *
 * Redis >= 7 replicates scripts by effects, so calling TIME before a write inside the script is allowed.
 */
final class LuaScripts
{
    public const KEY_PREFIX = 'rl:';

    /**
     * KEYS[1] sorted set of attempt timestamps (score = epoch ms, member = unique id)
     * ARGV[1] limit, ARGV[2] window ms, ARGV[3] member, ARGV[4] explicit now ms ('' = Redis TIME, tests only)
     * Returns {allowed, remaining, retry_after_ms, reset_at_ms, now_ms}
     */
    public const SLIDING_WINDOW = <<<'LUA'
        local key = KEYS[1]
        local limit = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local member = ARGV[3]
        local now
        if ARGV[4] ~= nil and ARGV[4] ~= '' then
            now = tonumber(ARGV[4])
        else
            local t = redis.call('TIME')
            now = t[1] * 1000 + math.floor(t[2] / 1000)
        end

        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)
        local count = redis.call('ZCARD', key)
        local allowed = 0
        local retry = 0
        if count < limit then
            redis.call('ZADD', key, now, member)
            count = count + 1
            allowed = 1
        else
            local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
            retry = (tonumber(oldest[2]) + window) - now
            if retry < 1 then retry = 1 end
        end
        -- PEXPIRE bounds the memory of every key to one window after its last attempt.
        redis.call('PEXPIRE', key, window)

        local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
        local reset = now + window
        if oldest[2] ~= nil then reset = tonumber(oldest[2]) + window end
        local remaining = limit - count
        if remaining < 0 then remaining = 0 end
        return {allowed, remaining, retry, reset, now}
        LUA;

    /**
     * Semaphore acquire. KEYS[1] sorted set of in-flight members (score = expiry ms).
     * ARGV[1] max, ARGV[2] ttl ms, ARGV[3] member, ARGV[4] explicit now ms ('' = Redis TIME)
     * Returns {acquired, in_flight, now_ms}
     */
    public const SEMAPHORE_ACQUIRE = <<<'LUA'
        local key = KEYS[1]
        local max = tonumber(ARGV[1])
        local ttl = tonumber(ARGV[2])
        local member = ARGV[3]
        local now
        if ARGV[4] ~= nil and ARGV[4] ~= '' then
            now = tonumber(ARGV[4])
        else
            local t = redis.call('TIME')
            now = t[1] * 1000 + math.floor(t[2] / 1000)
        end

        -- Slots whose expiry passed belong to crashed workers: free them first.
        redis.call('ZREMRANGEBYSCORE', key, '-inf', now)
        local count = redis.call('ZCARD', key)
        local acquired = 0
        if count < max then
            redis.call('ZADD', key, now + ttl, member)
            count = count + 1
            acquired = 1
        end
        redis.call('PEXPIRE', key, ttl)
        return {acquired, count, now}
        LUA;

    /**
     * @param  list<string>  $arguments
     * @return list<int|string>
     */
    public static function run(Connection $redis, string $script, string $key, array $arguments): array
    {
        $sha = sha1($script);
        try {
            if ($redis instanceof PhpRedisConnection) {
                // Laravel's PhpRedisConnection::evalsha() expects the script BODY and runs SCRIPT LOAD itself,
                // so a sha would be compiled as Lua. Talk to the phpredis client: real EVALSHA, EVAL on NOSCRIPT.
                $client = $redis->client();
                $params = [$key, ...$arguments];
                $result = $client->evalSha($sha, $params, 1);
                if ($result === false) {
                    $last = (string) $client->getLastError();
                    $client->clearLastError();
                    if (! self::isNoScript($last)) {
                        throw new \RuntimeException('Redis script failed: '.($last !== '' ? $last : 'unknown error'));
                    }
                    $result = $client->eval($script, $params, 1);
                }
            } else {
                try {
                    $result = $redis->command('evalsha', [$sha, 1, $key, ...$arguments]);
                } catch (Throwable $error) {
                    if (! self::isNoScript($error->getMessage())) {
                        throw $error;
                    }
                    $result = $redis->command('eval', [$script, 1, $key, ...$arguments]);
                }
            }
        } catch (Throwable $error) {
            throw LimiterBackendUnavailable::from($error);
        }

        if (! is_array($result)) {
            throw new LimiterBackendUnavailable('Redis script returned '.get_debug_type($result));
        }

        /** @var list<int|string> $result */
        return $result;
    }

    private static function isNoScript(string $message): bool
    {
        return str_contains(strtoupper($message), 'NOSCRIPT');
    }
}
