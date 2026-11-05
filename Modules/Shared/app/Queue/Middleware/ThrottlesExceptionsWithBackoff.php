<?php

declare(strict_types=1);

namespace Modules\Shared\Queue\Middleware;

use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Modules\Shared\Queue\Backoff;
use Throwable;

/**
 * ThrottlesExceptions (circuit breaker over a shared key, counters on cache.limiter = state Redis)
 * whose per-exception release delay follows the job's own exponential backoff() in seconds.
 * Upstream releases after `backoff` MINUTES (default 0 = immediately), which burns attempts.
 */
final class ThrottlesExceptionsWithBackoff extends ThrottlesExceptions
{
    private ?object $current = null;

    public function handle($job, $next)
    {
        $this->current = is_object($job) ? $job : null;
        try {
            return parent::handle($job, $next);
        } finally {
            $this->current = null;
        }
    }

    protected function getTimeUntilNextRetryAfterException(Throwable $throwable)
    {
        return $this->current === null ? 0 : Backoff::delayFor($this->current);
    }
}
