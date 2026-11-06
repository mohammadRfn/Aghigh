<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

use Modules\Shared\RateLimiting\LimiterBackendUnavailable;
use Modules\Shared\RateLimiting\RateLimitResult;

/**
 * Sliding-log rate limiter: at most `limit` attempts within any `windowSeconds` long interval.
 * Implementations: RedisSlidingWindowLimiter (Lua on the `state` connection) and InMemorySlidingWindowLimiter
 * (Feature suite). Both pass the same contract test (tests/Support/SlidingWindowContract.php).
 *
 * @throws LimiterBackendUnavailable when the backend cannot answer
 */
interface SlidingWindowLimiter
{
    /**
     * @param  int|null  $nowMs  explicit clock in epoch milliseconds, TESTS ONLY (production uses the Redis TIME)
     */
    public function attempt(string $key, int $limit, int $windowSeconds, ?int $nowMs = null): RateLimitResult;

    /** Drop every attempt recorded for the key (admin/test helper). */
    public function clear(string $key): void;
}
