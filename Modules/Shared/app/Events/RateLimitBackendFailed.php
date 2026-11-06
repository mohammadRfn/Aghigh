<?php

declare(strict_types=1);

namespace Modules\Shared\Events;

/** The limiter backend (state Redis) failed; `failMode` tells what the request got (open = served, closed = 503). */
final class RateLimitBackendFailed
{
    public function __construct(
        public readonly string $limiter,
        /** open | closed */
        public readonly string $failMode,
        public readonly string $errorClass,
    ) {}
}
