<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

use Modules\Shared\Contracts\Metrics;

/** METRICS_DRIVER=null or METRICS_ENABLED=false: records nothing, so Task 10 can measure the overhead of metrics. */
final class NullMetrics implements Metrics, MetricsStore
{
    public function counter(string $name, array $labels = [], float|int $by = 1): void {}

    public function gauge(string $name, float|int $value, array $labels = []): void {}

    public function histogram(string $name, float|int $value, array $labels = []): void {}

    public function render(): string
    {
        return '';
    }

    public function wipe(): void {}

    public function driver(): string
    {
        return 'null';
    }
}
