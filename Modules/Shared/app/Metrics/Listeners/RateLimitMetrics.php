<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics\Listeners;

use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Events\RateLimitBackendFailed;
use Modules\Shared\Events\RateLimitHit;
use Modules\Shared\Metrics\LabelValue;
use Modules\Shared\Metrics\Names;

/** Limiter events -> counters by limiter NAME (config/rate-limits.php) and driver; never by key, IP or route. */
final class RateLimitMetrics
{
    public function __construct(private readonly Metrics $metrics) {}

    public function hit(RateLimitHit $event): void
    {
        $this->metrics->counter(Names::RATE_LIMIT_HITS, [
            'limiter' => LabelValue::slug($event->limiter),
            'driver' => LabelValue::slug($event->driver),
        ]);
    }

    public function backendFailed(RateLimitBackendFailed $event): void
    {
        $this->metrics->counter(Names::STATE_STORE_ERRORS, ['component' => 'limiter']);
    }
}
