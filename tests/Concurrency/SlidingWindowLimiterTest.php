<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Modules\Shared\RateLimiting\RedisSlidingWindowLimiter;
use Tests\Support\Race;
use Tests\Support\RateLimitTesting;
use Tests\Support\SlidingWindowContract;

// Real Lua limiter on the `state` connection (REDIS_STATE_DB=12 under make test-int).

beforeEach(function (): void {
    RateLimitTesting::useRedis();
    Redis::connection('state')->flushdb();
});

function luaLimiter(): RedisSlidingWindowLimiter
{
    return new RedisSlidingWindowLimiter(app('redis'), 'state');
}

foreach (SlidingWindowContract::cases() as $name => $case) {
    it('Lua sliding window: '.$name, function () use ($case): void {
        $case(luaLimiter(), 'contract:'.bin2hex(random_bytes(4)));
    });
}

it('uses the Redis clock (TIME) when no explicit now is given', function (): void {
    $result = luaLimiter()->attempt('clock', 5, 60);
    expect(abs($result->nowMs - (int) floor(microtime(true) * 1000)))->toBeLessThan(5_000)
        ->and($result->resetAtMs)->toBe($result->nowMs + 60_000);
});

it('bounds memory: the sorted set never exceeds `limit` members and carries a PEXPIRE of one window', function (): void {
    $limiter = luaLimiter();
    foreach (range(1, 40) as $_) {
        $limiter->attempt('bounded', 10, 30);
    }
    $connection = Redis::connection('state');
    $key = RedisSlidingWindowLimiter::key('bounded');
    expect((int) $connection->zcard($key))->toBe(10)
        ->and((int) $connection->pttl($key))->toBeLessThanOrEqual(30_000)
        ->and((int) $connection->pttl($key))->toBeGreaterThan(25_000);
});

it('allows exactly 10 of 50 parallel processes against a limit of 10', function (): void {
    $key = 'parallel:'.bin2hex(random_bytes(4));
    $results = Race::run(base_path('tests/Concurrency/limiter-worker.php'), array_fill(0, 50, ['attempt', $key, 10, 60]));

    $allowed = count(array_filter($results, fn (array $r): bool => $r['ok'] && $r['allowed'] === true));
    $denied = array_values(array_filter($results, fn (array $r): bool => $r['ok'] && $r['allowed'] === false));
    expect($results)->toHaveCount(50)->and($allowed)->toBe(10)->and($denied)->toHaveCount(40)
        ->and((int) Redis::connection('state')->zcard(RedisSlidingWindowLimiter::key($key)))->toBe(10);
    foreach ($denied as $r) {
        expect($r['retry_after_ms'])->toBeGreaterThan(0)->toBeLessThanOrEqual(60_000);
    }
});

it('steady rate from parallel processes: two waves inside one window share the budget', function (): void {
    $key = 'waves:'.bin2hex(random_bytes(4));
    $first = Race::run(base_path('tests/Concurrency/limiter-worker.php'), array_fill(0, 8, ['attempt', $key, 10, 60]));
    $second = Race::run(base_path('tests/Concurrency/limiter-worker.php'), array_fill(0, 8, ['attempt', $key, 10, 60]));
    $count = fn (array $rs): int => count(array_filter($rs, fn (array $r): bool => $r['allowed'] === true));
    expect($count($first))->toBe(8)->and($count($second))->toBe(2);
});

it('recovers from NOSCRIPT after SCRIPT FLUSH by falling back to EVAL', function (): void {
    $limiter = luaLimiter();
    expect($limiter->attempt('flush', 3, 60)->allowed)->toBeTrue();
    Redis::connection('state')->script('flush');
    $after = $limiter->attempt('flush', 3, 60);
    expect($after->allowed)->toBeTrue()->and($after->remaining)->toBe(1); // state survived, script re-cached
    expect($limiter->attempt('flush', 3, 60)->remaining)->toBe(0);
    expect($limiter->attempt('flush', 3, 60)->allowed)->toBeFalse();
});

it('wraps a dead connection in LimiterBackendUnavailable so fail modes apply', function (): void {
    config(['database.redis.dead' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]]);
    $dead = new RedisSlidingWindowLimiter(app('redis'), 'dead');
    expect(fn () => $dead->attempt('x', 1, 1))->toThrow(\Modules\Shared\RateLimiting\LimiterBackendUnavailable::class);
});
