<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Carbon\CarbonImmutable;

/** Outcome of one limiter decision. Immutable; safe to keep on the request attributes under Octane. */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        /** 0 when allowed; otherwise milliseconds until the next attempt may succeed. */
        public readonly int $retryAfterMs,
        /** Epoch milliseconds when the window is fully free again (oldest member + window). */
        public readonly int $resetAtMs,
        /** Clock used by the backend (Redis TIME or the test clock), epoch milliseconds. */
        public readonly int $nowMs,
    ) {}

    public function resetAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs($this->resetAtMs);
    }

    /** Seconds until reset, as the IETF RateLimit-Reset header wants (delta-seconds, >= 0). */
    public function resetAfterSeconds(): int
    {
        return max(0, (int) ceil(($this->resetAtMs - $this->nowMs) / 1000));
    }

    /** Retry-After in whole seconds (ceil), at least 1 when denied. */
    public function retryAfterSeconds(): int
    {
        return $this->allowed ? 0 : max(1, (int) ceil($this->retryAfterMs / 1000));
    }

    /** True when $this is the more restrictive of two results (fewer remaining, then later reset). */
    public function isMoreRestrictiveThan(self $other): bool
    {
        if (! $this->allowed && $other->allowed) {
            return true;
        }
        if ($this->remaining !== $other->remaining) {
            return $this->remaining < $other->remaining;
        }

        return $this->resetAfterSeconds() > $other->resetAfterSeconds();
    }
}
