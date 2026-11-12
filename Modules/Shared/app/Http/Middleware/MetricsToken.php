<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bearer METRICS_TOKEN with a constant-time compare. An EMPTY token disables the endpoint: it answers 404 like any
 * unknown path, never open. Second line of defence: Traefik denies PathPrefix(/internal) before the app
 * (docker/traefik/dynamic.yml); Prometheus reaches app:8000 directly on the backend network.
 */
final class MetricsToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('shared.metrics.token', '');
        if ($expected === '') {
            throw new NotFoundHttpException;
        }

        $given = $request->bearerToken();
        if (! is_string($given) || $given === '' || ! hash_equals($expected, $given)) {
            return new HttpResponse('', 401, [
                'WWW-Authenticate' => 'Bearer realm="metrics"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        return $next($request);
    }
}
