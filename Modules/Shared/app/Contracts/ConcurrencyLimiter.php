<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

use Modules\Shared\RateLimiting\LimiterBackendUnavailable;

/**
 * Semaphore of in-flight requests per key. A slot is owned by `member` (the request id) and expires
 * after `ttlMs` so a crashed worker can never leak it forever. Release is idempotent and only removes
 * the caller's own slot.
 *
 * @throws LimiterBackendUnavailable when the backend cannot answer
 */
interface ConcurrencyLimiter
{
    public function acquire(string $key, string $member, int $max, int $ttlMs, ?int $nowMs = null): bool;

    public function release(string $key, string $member): void;

    public function inFlight(string $key, ?int $nowMs = null): int;
}
