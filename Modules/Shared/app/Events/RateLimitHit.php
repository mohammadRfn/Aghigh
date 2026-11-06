<?php

declare(strict_types=1);

namespace Modules\Shared\Events;

/** A request was rejected by a limiter (HTTP limiter, concurrency cap or business limit). Fixed fields for Task 9/10. */
final class RateLimitHit
{
    public function __construct(
        public readonly string $limiter,
        /** Truncated HMAC of the limiter key; never the raw key. */
        public readonly string $keyHash,
        public readonly ?string $ip,
        public readonly ?string $route,
        /** laravel | sliding | concurrency | business */
        public readonly string $driver,
        public readonly int $retryAfterSeconds,
    ) {}
}
