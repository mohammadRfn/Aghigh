<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\Job;
use Mockery;
use Modules\Shared\Metrics\MetricsStore;

/** Helpers for the Task 9 metrics tests (memory driver: one registry per test, wiped by the fresh app). */
final class MetricsTesting
{
    /** Text exposition of everything written through the Metrics contract in this test. */
    public static function body(): string
    {
        return (string) app(MetricsStore::class)->render();
    }

    /** Value of one exact series, e.g. `aghigh_orders_total{outcome="paid"}`; null when the series is absent. */
    public static function sample(string $series): ?float
    {
        foreach (preg_split('/\R/', self::body()) ?: [] as $line) {
            if (str_starts_with($line, $series.' ')) {
                return (float) substr($line, strlen($series) + 1);
            }
        }

        return null;
    }

    /** True when any sample line of this metric name (any labels) exists. */
    public static function has(string $name): bool
    {
        foreach (preg_split('/\R/', self::body()) ?: [] as $line) {
            if ($line !== '' && $line[0] !== '#' && (str_starts_with($line, $name.'{') || str_starts_with($line, $name.' '))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    public static function job(array $payload = [], int $attempts = 1, string $queue = 'orders', string $name = 'Modules\\Ordering\\Jobs\\ChargePayment'): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldReceive('getQueue')->andReturn($queue);
        $job->shouldReceive('resolveName')->andReturn($name);
        $job->shouldReceive('getName')->andReturn($name);

        return $job;
    }
}
