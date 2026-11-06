<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Shared\Contracts\ConcurrencyLimiter;
use Modules\Shared\Contracts\SlidingWindowLimiter;
use Modules\Shared\RateLimiting\InMemoryConcurrencyLimiter;
use Modules\Shared\RateLimiting\InMemorySlidingWindowLimiter;
use Modules\Shared\RateLimiting\RedisConcurrencyLimiter;
use Modules\Shared\RateLimiting\RedisSlidingWindowLimiter;
use PHPUnit\Framework\Assert;

/**
 * Limiter tests pin exact values: multiplier 1 (phpunit.xml uses 1000 so unrelated tests never trip)
 * and the given per-limiter limits. Also exposes the in-memory doubles for failure injection.
 */
final class RateLimitTesting
{
    /** @param array<string, int> $limits limiter name -> limit (window stays as configured) */
    public static function exact(array $limits = [], ?int $ordersPerMinute = null, ?int $concurrency = null): void
    {
        $config = ['rate-limits.multiplier' => 1, 'rate-limits.log.sample_after' => 1000];
        foreach ($limits as $name => $limit) {
            $config["rate-limits.limiters.{$name}.limit"] = $limit;
        }
        if ($ordersPerMinute !== null) {
            $config['rate-limits.business.orders_per_minute'] = $ordersPerMinute;
        }
        if ($concurrency !== null) {
            $config['rate-limits.concurrency.orders.max'] = $concurrency;
        }
        config($config);
        self::reset();
    }

    /** Forget every counter of the in-memory doubles and Laravel's array limiter store. */
    public static function reset(): void
    {
        $sliding = app(SlidingWindowLimiter::class);
        if ($sliding instanceof InMemorySlidingWindowLimiter) {
            $sliding->reset();
        }
        $semaphore = app(ConcurrencyLimiter::class);
        if ($semaphore instanceof InMemoryConcurrencyLimiter) {
            $semaphore->reset();
        }
        app('cache')->store((string) config('cache.limiter'))->flush();
    }

    public static function memorySliding(): InMemorySlidingWindowLimiter
    {
        $limiter = app(SlidingWindowLimiter::class);
        Assert::assertInstanceOf(InMemorySlidingWindowLimiter::class, $limiter, 'Feature suite must run with RATE_LIMIT_SLIDING_DRIVER=memory.');

        return $limiter;
    }

    public static function memorySemaphore(): InMemoryConcurrencyLimiter
    {
        $limiter = app(ConcurrencyLimiter::class);
        Assert::assertInstanceOf(InMemoryConcurrencyLimiter::class, $limiter);

        return $limiter;
    }

    /** Concurrency suite: force the real Lua drivers on the `state` connection regardless of env. */
    public static function useRedis(): void
    {
        config(['rate-limits.sliding_driver' => 'redis', 'rate-limits.multiplier' => 1]);
        app()->instance(SlidingWindowLimiter::class, new RedisSlidingWindowLimiter(app('redis'), 'state'));
        app()->instance(ConcurrencyLimiter::class, new RedisConcurrencyLimiter(app('redis'), 'state'));
    }
}
