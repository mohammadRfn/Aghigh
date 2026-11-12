<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Metrics\Names;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global middleware: starts a clock in handle() and records the counter + latency histogram in terminate(), i.e.
 * after the response was sent (Octane calls terminate post-send). Labels are bounded: route NAME (or URI template
 * when unnamed), method from a fixed list, status class. Unmatched requests (404/405 from the router) are
 * route="unmatched". /up and /internal/metrics are skipped.
 */
final class RecordHttpMetrics
{
    private const ATTRIBUTE = 'metrics_started_at_ns';

    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function __construct(private readonly Metrics $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($request->is('up') || $request->is('internal/metrics')) {
                return;
            }
            $method = strtoupper($request->getMethod());
            $labels = [
                'route' => self::routeLabel($request),
                'method' => in_array($method, self::METHODS, true) ? $method : 'other',
            ];
            $status = $response->getStatusCode();
            $this->metrics->counter(Names::HTTP_REQUESTS, $labels + ['status_class' => intdiv($status, 100).'xx']);

            $started = $request->attributes->get(self::ATTRIBUTE);
            if (is_int($started)) {
                $this->metrics->histogram(Names::HTTP_DURATION, (hrtime(true) - $started) / 1e9, $labels);
            }
        } catch (Throwable) {
            // metrics never break a request, even here
        }
    }

    public static function routeLabel(Request $request): string
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return 'unmatched';
        }
        $name = $route->getName();
        if (is_string($name) && $name !== '' && ! str_starts_with($name, 'generated::')) {
            return $name;
        }

        return '/'.ltrim($route->uri(), '/');
    }
}
