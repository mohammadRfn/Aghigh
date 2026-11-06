<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use RuntimeException;
use Throwable;

/** Wraps any Redis/connection failure of a limiter backend so callers can apply their fail mode. */
final class LimiterBackendUnavailable extends RuntimeException
{
    public static function from(Throwable $error): self
    {
        return new self($error::class.': '.$error->getMessage(), 0, $error);
    }
}
