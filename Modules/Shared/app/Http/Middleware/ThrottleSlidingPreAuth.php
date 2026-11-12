<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same behaviour as ThrottleSliding, but a distinct class so bootstrap/app.php can put it in the middleware
 * priority list BEFORE AuthenticatesRequests. ThrottleSliding itself cannot be listed: the same class also runs
 * after auth (per user), and Laravel's priority sort works on the class name, not on the parameters.
 * Use as `throttle.sliding.pre:<limiter>` for per-IP shields that must run before `auth:api`.
 */
final class ThrottleSlidingPreAuth
{
    public function __construct(private readonly ThrottleSliding $inner) {}

    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        return $this->inner->handle($request, $next, $limiter);
    }
}
