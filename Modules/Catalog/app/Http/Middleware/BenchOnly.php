<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Catalog\Support\BenchEnvironment;
use Symfony\Component\HttpFoundation\Response;

final class BenchOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        // Defense against accidentally deploying a local-generated route cache.
        if (! BenchEnvironment::enabled(app()->environment())) {
            throw new CatalogProblem('Route not found.', 404, 'not-found');
        }

        return $next($request);
    }
}
