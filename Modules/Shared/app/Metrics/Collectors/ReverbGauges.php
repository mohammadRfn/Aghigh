<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics\Collectors;

use GuzzleHttp\Client;
use Modules\Shared\Metrics\Names;
use Modules\Shared\Metrics\ScrapeCache;
use Prometheus\CollectorRegistry;
use Pusher\Pusher;
use Throwable;

/**
 * reverb_pusher_api_connections: a SIGNED call to Reverb's Pusher-compatible HTTP API (GET /apps/{id}/connections)
 * over the backend network, with a short timeout and a short cache so a slow Reverb never slows a scrape.
 *
 * What the number means (Reverb 1.12.0 source, verified): ConnectionsController -> MetricsHandler::gather(app,
 * 'connections'); when REVERB_SCALING_ENABLED the handler asks every replica over Redis pub/sub and merges the lists
 * (global count, up to a 10 s internal timeout), otherwise it answers for the local server only. The per-server
 * source is ChannelManager::connections(), i.e. connections that joined at least one channel: the Task 8
 * observation (5 reported, 19 sockets ESTABLISHED) is consistent with that. Reverb exposes no socket count.
 * On any failure the connections sample is OMITTED and reverb_scrape_up is 0.
 */
final class ReverbGauges
{
    public const CACHE_KEY = 'reverb';

    public function __construct(
        private readonly ScrapeCache $cache,
        private readonly int $ttlSeconds,
        private readonly float $timeoutSeconds,
    ) {}

    public function collect(CollectorRegistry $registry): void
    {
        $config = config('broadcasting.connections.reverb');
        if (config('broadcasting.default') !== 'reverb' || ! is_array($config)) {
            return; // not configured (tests, BROADCAST_CONNECTION=null): no samples at all
        }
        $key = (string) ($config['key'] ?? '');
        $secret = (string) ($config['secret'] ?? '');
        $appId = (string) ($config['app_id'] ?? '');
        if ($key === '' || $secret === '' || $appId === '') {
            return;
        }

        $result = $this->cache->remember(self::CACHE_KEY, $this->ttlSeconds, fn (): array => $this->fetch($key, $secret, $appId, (array) ($config['options'] ?? [])));

        $registry->getOrRegisterGauge('', Names::SCRAPE_REVERB_UP, 'Whether the last signed GET /apps/{id}/connections call to Reverb succeeded within the timeout (1) or not (0).')
            ->set(($result['up'] ?? false) === true ? 1.0 : 0.0);

        if (($result['up'] ?? false) === true && is_numeric($result['connections'] ?? null)) {
            $registry->getOrRegisterGauge('', Names::SCRAPE_REVERB_CONNECTIONS, 'Connections reported by Reverb\'s Pusher API (GET /apps/{id}/connections); observed to count channel-subscribed connections, not all open sockets. Merged across replicas when REVERB_SCALING_ENABLED, local only otherwise.')
                ->set((float) $result['connections']);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{up: bool, connections?: int, error?: string}
     */
    private function fetch(string $key, string $secret, string $appId, array $options): array
    {
        try {
            $scheme = (string) ($options['scheme'] ?? 'http');
            $client = new Client([
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => min(0.5, $this->timeoutSeconds),
                'http_errors' => false,
            ]);
            $pusher = new Pusher($key, $secret, $appId, [
                'host' => (string) ($options['host'] ?? 'reverb'),
                'port' => (int) ($options['port'] ?? 8080),
                'scheme' => $scheme,
                'useTLS' => $scheme === 'https',
                'timeout' => (int) max(1, ceil($this->timeoutSeconds)),
            ], $client);
            $response = $pusher->get('/connections', [], true);
            $connections = is_array($response) ? ($response['connections'] ?? null) : null;
            if (! is_numeric($connections)) {
                return ['up' => false, 'error' => 'unexpected body'];
            }

            return ['up' => true, 'connections' => (int) $connections];
        } catch (Throwable $error) {
            return ['up' => false, 'error' => $error::class];
        }
    }
}
