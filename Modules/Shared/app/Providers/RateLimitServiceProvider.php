<?php

declare(strict_types=1);

namespace Modules\Shared\Providers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Shared\Contracts\ConcurrencyLimiter;
use Modules\Shared\Contracts\SlidingWindowLimiter;
use Modules\Shared\Http\Middleware\ConcurrencyCap;
use Modules\Shared\Http\Middleware\ThrottleSliding;
use Modules\Shared\RateLimiting\InMemoryConcurrencyLimiter;
use Modules\Shared\RateLimiting\InMemorySlidingWindowLimiter;
use Modules\Shared\RateLimiting\LimiterRegistry;
use Modules\Shared\RateLimiting\RateLimitMultiplier;
use Modules\Shared\RateLimiting\RateLimitReporter;
use Modules\Shared\RateLimiting\RedisConcurrencyLimiter;
use Modules\Shared\RateLimiting\RedisSlidingWindowLimiter;
use Modules\Shared\Support\UnsafeEnvironment;
use RuntimeException;

/**
 * Registers the named limiters' machinery (config/rate-limits.php), the `throttle.sliding` and
 * `concurrency.cap` middleware aliases and the limiter backends. Replaces Identity's SecurityLimits (Task 3):
 * the auth limiters keep their keys and values, they are just defined centrally now.
 *
 * All singletons here are stateless (registry, reporter, Redis drivers) or test-only (in-memory drivers),
 * which is what makes them safe under Octane.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('rate-limits.php'), 'rate-limits');

        $this->app->singleton(LimiterRegistry::class, fn (Application $app): LimiterRegistry => new LimiterRegistry($app->make(Repository::class), $app));

        $this->app->singleton(RateLimitReporter::class, fn (Application $app): RateLimitReporter => new RateLimitReporter(
            $app->make(LogManager::class),
            $app->make(Dispatcher::class),
            $app->make(CacheFactory::class),
            $app->make(Repository::class),
        ));

        $this->app->singleton(SlidingWindowLimiter::class, function (Application $app): SlidingWindowLimiter {
            return $this->usesMemoryDriver($app)
                ? new InMemorySlidingWindowLimiter
                : new RedisSlidingWindowLimiter($app->make(RedisFactory::class), 'state');
        });

        $this->app->singleton(ConcurrencyLimiter::class, function (Application $app): ConcurrencyLimiter {
            return $this->usesMemoryDriver($app)
                ? new InMemoryConcurrencyLimiter
                : new RedisConcurrencyLimiter($app->make(RedisFactory::class), 'state');
        });
    }

    public function boot(Router $router): void
    {
        $environment = (string) $this->app->environment();
        // Boot check: a multiplier outside local/testing/loadtest is a misconfiguration and must fail loudly.
        RateLimitMultiplier::assertAllowed($environment, $this->app->make(Repository::class)->get('rate-limits.multiplier'));

        if ($this->usesMemoryDriver($this->app) && ! UnsafeEnvironment::allows($environment)) {
            throw new RuntimeException('RATE_LIMIT_SLIDING_DRIVER=memory is a test double and is refused in APP_ENV='.$environment.'.');
        }

        $router->aliasMiddleware('throttle.sliding', ThrottleSliding::class);
        $router->aliasMiddleware('concurrency.cap', ConcurrencyCap::class);
    }

    private function usesMemoryDriver(Application $app): bool
    {
        return (string) $app->make(Repository::class)->get('rate-limits.sliding_driver', 'redis') === 'memory';
    }
}
