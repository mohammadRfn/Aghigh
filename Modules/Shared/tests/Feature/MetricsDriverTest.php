<?php

declare(strict_types=1);

use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Metrics\MetricsStore;
use Modules\Shared\Metrics\NullMetrics;
use Modules\Shared\Metrics\PrometheusMetrics;
use Modules\Shared\Providers\MetricsServiceProvider;

function rebindMetrics(): void
{
    app()->forgetInstance(Metrics::class);
    app()->forgetInstance(MetricsStore::class);
}

it('METRICS_ENABLED=false forces the null driver whatever METRICS_DRIVER says', function (): void {
    config(['shared.metrics.enabled' => false, 'shared.metrics.driver' => 'redis']);
    rebindMetrics();

    expect(MetricsServiceProvider::driver())->toBe('null')
        ->and(app(Metrics::class))->toBeInstanceOf(NullMetrics::class)
        ->and(app(MetricsStore::class)->driver())->toBe('null')
        ->and(app(MetricsStore::class)->render())->toBe('');
});

it('the null driver accepts every call and records nothing', function (): void {
    $metrics = new NullMetrics;

    expect(fn () => $metrics->counter('whatever', ['x' => 'y'], 5))->not->toThrow(Throwable::class)
        ->and(fn () => $metrics->gauge('whatever', 1.5))->not->toThrow(Throwable::class)
        ->and(fn () => $metrics->histogram('whatever', 0.2))->not->toThrow(Throwable::class)
        ->and(fn () => $metrics->wipe())->not->toThrow(Throwable::class)
        ->and($metrics->render())->toBe('');
});

it('resolves the memory driver outside production', function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
    rebindMetrics();

    expect(app(Metrics::class))->toBeInstanceOf(PrometheusMetrics::class)
        ->and(app(MetricsStore::class)->driver())->toBe('memory')
        ->and(app(Metrics::class))->toBe(app(MetricsStore::class));
});

it('refuses the per-process memory driver in production', function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
    $this->app['env'] = 'production';
    rebindMetrics();

    expect(fn () => app(Metrics::class))->toThrow(InvalidArgumentException::class, 'only meant for tests');
});

it('rejects an unknown driver instead of silently recording nothing', function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'statsd']);
    rebindMetrics();

    expect(fn () => app(Metrics::class))->toThrow(InvalidArgumentException::class, 'redis|memory|null');
});

it('builds the redis driver lazily: resolving it never opens a connection', function (): void {
    config([
        'shared.metrics.enabled' => true,
        'shared.metrics.driver' => 'redis',
        'database.redis.metrics.host' => '127.0.0.1',
        'database.redis.metrics.port' => 1,
    ]);
    rebindMetrics();

    expect(app(Metrics::class))->toBeInstanceOf(PrometheusMetrics::class)
        ->and(app(MetricsStore::class)->driver())->toBe('redis');
});
