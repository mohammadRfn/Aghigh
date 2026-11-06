<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Modules\Shared\Events\RateLimitBackendFailed;
use Modules\Shared\Events\RateLimitHit;
use Throwable;

/**
 * Every rejected request: `security` log line with the FIXED message `rate_limit_hit` and fixed fields
 * (limiter, key_hash, ip, route, driver, retry_after, suppressed) + the RateLimitHit event (Task 9 metrics).
 * Above `log.sample_after` hits per (limiter, key) per window the log is sampled 1 in `log.sample_every`
 * with the number of lines skipped in `suppressed`; the event is still dispatched for every hit (in-process).
 */
final class RateLimitReporter
{
    public function __construct(
        private readonly LogManager $log,
        private readonly Dispatcher $events,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    public function hit(string $limiter, string $key, Request $request, string $driver, int $retryAfterSeconds, int $windowSeconds): void
    {
        $hash = RateLimitKey::hash($key);
        $route = $request->route()?->getName();
        $ip = $request->ip();

        $this->events->dispatch(new RateLimitHit($limiter, $hash, $ip, $route, $driver, $retryAfterSeconds));

        [$log, $suppressed] = $this->sample($limiter, $hash, $windowSeconds);
        if (! $log) {
            return;
        }
        $this->security()->info('rate_limit_hit', [
            'limiter' => $limiter,
            'key_hash' => $hash,
            'ip' => $ip,
            'route' => $route,
            'driver' => $driver,
            'retry_after' => $retryAfterSeconds,
            'suppressed' => $suppressed,
        ]);
    }

    public function backendFailed(string $limiter, string $failMode, Throwable $error): void
    {
        $previous = $error instanceof LimiterBackendUnavailable ? $error->getPrevious() : null;
        $class = $previous !== null ? $previous::class : $error::class;
        $this->events->dispatch(new RateLimitBackendFailed($limiter, $failMode, $class));
        $this->security()->error('rate_limit_backend_failed', [
            'limiter' => $limiter,
            'fail_mode' => $failMode,
            'error' => $class,
        ]);
    }

    /** Resolved per call so a test (or a config reload under Octane) can swap the channel. */
    private function security(): \Psr\Log\LoggerInterface
    {
        return $this->log->channel((string) $this->config->get('rate-limits.log.channel', 'security'));
    }

    /** @return array{0: bool, 1: int} [log this hit?, suppressed count] */
    private function sample(string $limiter, string $hash, int $windowSeconds): array
    {
        $after = max(0, (int) $this->config->get('rate-limits.log.sample_after', 20));
        $every = max(1, (int) $this->config->get('rate-limits.log.sample_every', 100));
        try {
            $store = $this->cache->store((string) $this->config->get('cache.limiter', 'state'));
            $counter = LuaScripts::KEY_PREFIX.'log:'.$limiter.':'.$hash;
            $store->add($counter, 0, max(1, $windowSeconds));
            $count = (int) $store->increment($counter);
        } catch (Throwable) {
            return [true, 0]; // the counter is best effort: without it, log every hit
        }
        if ($count <= $after) {
            return [true, 0];
        }
        $over = $count - $after;

        return $over % $every === 0 ? [true, $every - 1] : [false, 0];
    }
}
