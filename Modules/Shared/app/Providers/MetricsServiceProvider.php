<?php

declare(strict_types=1);

namespace Modules\Shared\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Events\RateLimitBackendFailed;
use Modules\Shared\Events\RateLimitHit;
use Modules\Shared\Http\Controllers\MetricsController;
use Modules\Shared\Http\Middleware\MetricsToken;
use Modules\Shared\Metrics\Collectors\QueueGauges;
use Modules\Shared\Metrics\Collectors\ReverbGauges;
use Modules\Shared\Metrics\Listeners\QueueEventMetrics;
use Modules\Shared\Metrics\Listeners\RateLimitMetrics;
use Modules\Shared\Metrics\MetricsStore;
use Modules\Shared\Metrics\NullMetrics;
use Modules\Shared\Metrics\PrometheusMetrics;
use Modules\Shared\Metrics\ScrapeCache;
use Modules\Shared\Queue\QueueMetrics;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as RedisStorage;
use Redis as PhpRedis;
use RuntimeException;

/**
 * Task 9: binds the Metrics contract by METRICS_DRIVER (redis | memory | null; METRICS_ENABLED=false forces null),
 * the scrape-time collectors, the /internal/metrics route and the Shared listeners. Storage for the redis driver is
 * the DEDICATED Laravel Redis connection `metrics` (redis-queue, db REDIS_METRICS_DB, default 3; ADR 0010), never
 * `state`: metrics need SCAN/DEL resets that must not touch correctness data.
 */
final class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Metrics::class, function (Application $app): Metrics {
            $driver = self::driver();
            $connection = (string) config('shared.metrics.connection', 'metrics');

            return match ($driver) {
                'null' => new NullMetrics,
                'memory' => $this->memory($app),
                'redis' => new PrometheusMetrics(
                    storage: function () use ($app, $connection): Adapter {
                        RedisStorage::setPrefix((string) config('shared.metrics.key_prefix', 'metrics:'));
                        $client = $app->make(RedisFactory::class)->connection($connection)->client();
                        if (! $client instanceof PhpRedis) {
                            throw new RuntimeException('METRICS_DRIVER=redis needs REDIS_CLIENT=phpredis.');
                        }

                        return RedisStorage::fromExistingConnection($client);
                    },
                    driver: 'redis',
                    strict: false,
                    reset: function () use ($app, $connection): void {
                        $manager = $app->make(RedisFactory::class);
                        if ($manager instanceof RedisManager) {
                            $manager->purge($connection); // next write re-connects instead of reusing a dead socket
                        }
                    },
                ),
                default => throw new InvalidArgumentException('METRICS_DRIVER must be redis|memory|null.'),
            };
        });
        $this->app->singleton(MetricsStore::class, fn (Application $app): MetricsStore => $app->make(Metrics::class) instanceof MetricsStore ? $app->make(Metrics::class) : new NullMetrics);

        $this->app->singleton(ScrapeCache::class, fn (Application $app): ScrapeCache => new ScrapeCache(
            $app->make(RedisFactory::class),
            self::driver() === 'redis' ? (string) config('shared.metrics.connection', 'metrics') : null,
        ));
        $this->app->singleton(QueueGauges::class, fn (Application $app): QueueGauges => new QueueGauges(
            $app->make(QueueMetrics::class),
            $app->make(ScrapeCache::class),
            max(0, (int) config('shared.metrics.queue_cache_seconds', 5)),
        ));
        $this->app->singleton(ReverbGauges::class, fn (Application $app): ReverbGauges => new ReverbGauges(
            $app->make(ScrapeCache::class),
            max(0, (int) config('shared.metrics.reverb.cache_seconds', 10)),
            min(1.0, max(0.1, (float) config('shared.metrics.reverb.timeout_seconds', 1.0))),
        ));
        $this->app->singleton(QueueEventMetrics::class);
    }

    public function boot(): void
    {
        // No `web`/`api` group: no session, no limiter, no CSRF. Global middleware (request id, security headers,
        // RecordHttpMetrics which skips this path) still applies. Traefik denies /internal before it gets here.
        Route::get('internal/metrics', MetricsController::class)
            ->middleware(MetricsToken::class)
            ->name('internal.metrics');

        Event::subscribe(QueueEventMetrics::class);
        Event::listen(RateLimitHit::class, [RateLimitMetrics::class, 'hit']);
        Event::listen(RateLimitBackendFailed::class, [RateLimitMetrics::class, 'backendFailed']);
    }

    /** null when disabled, otherwise the configured driver name. */
    public static function driver(): string
    {
        if (! (bool) config('shared.metrics.enabled', true)) {
            return 'null';
        }

        return (string) config('shared.metrics.driver', 'redis');
    }

    private function memory(Application $app): Metrics
    {
        if ($app->environment('production')) {
            throw new InvalidArgumentException('METRICS_DRIVER=memory is per process and only meant for tests.');
        }

        return new PrometheusMetrics(storage: static fn (): Adapter => new InMemory, driver: 'memory', strict: true);
    }
}
