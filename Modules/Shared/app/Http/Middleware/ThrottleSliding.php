<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Modules\Shared\Contracts\SlidingWindowLimiter;
use Modules\Shared\Exceptions\LimiterUnavailable;
use Modules\Shared\Exceptions\RateLimitExceeded;
use Modules\Shared\RateLimiting\LimiterDefinition;
use Modules\Shared\RateLimiting\LimiterRegistry;
use Modules\Shared\RateLimiting\RateLimitHeaders;
use Modules\Shared\RateLimiting\RateLimitKey;
use Modules\Shared\RateLimiting\RateLimitReporter;
use Modules\Shared\RateLimiting\RateLimitResult;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `throttle.sliding:{limiter}`: ONE middleware for every named limiter in config/rate-limits.php.
 * The limiter's `driver` picks the engine (Laravel's fixed-window RateLimiter or the Lua sliding log);
 * headers, logging, events and the 429 body are identical either way.
 *
 * Fail mode on backend errors: `open` serves the request without headers, `closed` answers 503
 * `rate-limit-unavailable`. Both dispatch RateLimitBackendFailed.
 *
 * Stateless: everything is derived per request from config + the request; nothing is memoised on the
 * instance, so the class is safe as an Octane singleton.
 */
final class ThrottleSliding
{
    public const ATTRIBUTE = 'rate_limit.results';

    public function __construct(
        private readonly LimiterRegistry $registry,
        private readonly SlidingWindowLimiter $sliding,
        private readonly RateLimiter $laravel,
        private readonly RateLimitReporter $reporter,
    ) {}

    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        $definition = $this->registry->get($limiter);
        $key = RateLimitKey::for($definition->name, $definition->by, $request);

        try {
            $result = $this->decide($definition, $key);
        } catch (Throwable $error) {
            $this->reporter->backendFailed($definition->name, $definition->failMode, $error);
            if ($definition->failsOpen()) {
                return $next($request);
            }
            throw new LimiterUnavailable($definition->name);
        }

        if (! $result->allowed) {
            $this->reporter->hit($definition->name, $key, $request, $definition->driver, $result->retryAfterSeconds(), $definition->windowSeconds);
            // Thrown, not returned: the 429 never reaches inner middleware, so no Idempotency-Key is stored.
            throw RateLimitExceeded::fromResult($result);
        }

        $response = $next($request);
        RateLimitHeaders::apply($response, $result);

        return $response;
    }

    private function decide(LimiterDefinition $definition, string $key): RateLimitResult
    {
        if ($definition->driver === 'sliding') {
            return $this->sliding->attempt($key, $definition->limit, $definition->windowSeconds);
        }

        return $this->decideWithLaravel($definition, $key);
    }

    /** Laravel's fixed window, translated to the same result shape. Its own exceptions bubble up as backend errors. */
    private function decideWithLaravel(LimiterDefinition $definition, string $key): RateLimitResult
    {
        $cacheKey = 'rl:lw:'.$definition->name.':'.sha1($key);
        $nowMs = (int) floor(microtime(true) * 1000);

        if ($this->laravel->tooManyAttempts($cacheKey, $definition->limit)) {
            $retry = max(1, $this->laravel->availableIn($cacheKey)) * 1000;

            return new RateLimitResult(false, $definition->limit, 0, $retry, $nowMs + $retry, $nowMs);
        }

        $hits = $this->laravel->hit($cacheKey, $definition->windowSeconds);
        $remaining = max(0, $definition->limit - $hits);
        $resetIn = max(1, $this->laravel->availableIn($cacheKey)) * 1000;

        return new RateLimitResult(true, $definition->limit, $remaining, 0, $nowMs + $resetIn, $nowMs);
    }
}
