<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Shared\Contracts\ConcurrencyLimiter;
use Modules\Shared\Exceptions\LimiterUnavailable;
use Modules\Shared\Exceptions\RateLimitExceeded;
use Modules\Shared\RateLimiting\LimiterRegistry;
use Modules\Shared\RateLimiting\RateLimitKey;
use Modules\Shared\RateLimiting\RateLimitReporter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `concurrency.cap:{name}`: per-user cap on in-flight requests (config rate-limits.concurrency.{name}).
 * Acquire before the controller, release in `finally` (idempotent, only our own slot). The slot carries an
 * expiry > the longest request, so a worker that dies without reaching `finally` leaks nothing for long.
 * Runs AFTER auth:api (needs the user) and BEFORE RequireIdempotencyKey (a 429 must not store a key).
 */
final class ConcurrencyCap
{
    public function __construct(
        private readonly LimiterRegistry $registry,
        private readonly ConcurrencyLimiter $semaphore,
        private readonly RateLimitReporter $reporter,
    ) {}

    public function handle(Request $request, Closure $next, string $name): Response
    {
        $settings = $this->registry->concurrency($name);
        $key = RateLimitKey::for('cc-'.$name, 'user', $request);
        $member = (string) ($request->attributes->get('request_id') ?: Str::uuid7());

        try {
            $acquired = $this->semaphore->acquire($key, $member, $settings['max'], $settings['ttl_ms']);
        } catch (Throwable $error) {
            $this->reporter->backendFailed('concurrency:'.$name, $settings['fail'], $error);
            if ($settings['fail'] === 'open') {
                return $next($request);
            }
            throw new LimiterUnavailable('concurrency:'.$name);
        }

        if (! $acquired) {
            $this->reporter->hit('concurrency:'.$name, $key, $request, 'concurrency', 1, (int) ceil($settings['ttl_ms'] / 1000));
            throw RateLimitExceeded::concurrency($settings['max']);
        }

        try {
            return $next($request);
        } finally {
            try {
                $this->semaphore->release($key, $member);
            } catch (Throwable) {
                // Best effort: the slot expires by itself; never turn a finished request into an error.
            }
        }
    }
}
