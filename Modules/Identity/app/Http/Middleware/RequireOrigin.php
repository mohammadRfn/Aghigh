<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\IdentityProblem;
use Symfony\Component\HttpFoundation\Response;

final class RequireOrigin
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $allowed = config('cors.allowed_origins', []);
        if (! is_string($origin) || $origin === 'null' || $origin === '*' || ! in_array($origin, $allowed, true)) {
            throw new IdentityProblem('origin-not-allowed', 403, 'An explicitly allowed Origin header is required.');
        }

        return $next($request);
    }
}
