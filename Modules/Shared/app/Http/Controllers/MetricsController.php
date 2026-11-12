<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Controllers;

use Illuminate\Http\Response;
use Modules\Shared\Metrics\Collectors\QueueGauges;
use Modules\Shared\Metrics\Collectors\ReverbGauges;
use Modules\Shared\Metrics\MetricsStore;
use Modules\Shared\Metrics\Names;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Throwable;

/**
 * GET /internal/metrics (bearer METRICS_TOKEN, see MetricsToken). Text exposition 0.0.4:
 *   1. everything written through the Metrics contract (Redis-backed registry), then
 *   2. scrape-time gauges built in a throw-away in-memory registry: queues (QueueMetrics, cached 5 s),
 *      Reverb (signed call, cached 10 s), and the health of this very endpoint.
 * Never cached by anyone (Cache-Control: no-store), never behind a limiter or a session.
 */
final class MetricsController
{
    public function __invoke(MetricsStore $store, QueueGauges $queues, ReverbGauges $reverb): Response
    {
        $app = $store->render();
        $scrape = new CollectorRegistry(new InMemory, false);

        if ($store->driver() === 'null') {
            $scrape->getOrRegisterGauge('', Names::SCRAPE_ENABLED, 'METRICS_ENABLED=false or METRICS_DRIVER=null: the app records nothing; only scrape-time gauges are exposed.')->set(0.0);
        } else {
            $scrape->getOrRegisterGauge('', Names::SCRAPE_BACKEND_UP, 'Whether the metrics storage (Redis db REDIS_METRICS_DB) could be read for this scrape.')->set($app === null ? 0.0 : 1.0);
        }

        foreach ([$queues, $reverb] as $collector) {
            try {
                $collector->collect($scrape);
            } catch (Throwable) {
                // a collector must never take the endpoint down
            }
        }

        $body = (string) $app;
        $scrape->getOrRegisterGauge('', Names::SCRAPE_SERIES, 'Number of samples in the app part of this exposition (cardinality watchdog, ADR 0010).')
            ->set((float) self::countSamples($body));

        $body .= (new RenderTextFormat)->render($scrape->getMetricFamilySamples());

        return new Response($body, 200, [
            'Content-Type' => RenderTextFormat::MIME_TYPE,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public static function countSamples(string $exposition): int
    {
        $count = 0;
        foreach (preg_split('/\R/', $exposition) ?: [] as $line) {
            if ($line !== '' && $line[0] !== '#') {
                $count++;
            }
        }

        return $count;
    }
}
