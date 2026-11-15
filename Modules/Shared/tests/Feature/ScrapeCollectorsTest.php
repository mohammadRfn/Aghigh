<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Modules\Shared\Metrics\Collectors\QueueGauges;
use Modules\Shared\Metrics\Collectors\ReverbGauges;
use Modules\Shared\Metrics\ScrapeCache;
use Modules\Shared\Queue\QueueMetrics;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/** A ScrapeCache whose Redis already holds $cached for $key (so the compute closure never runs). */
function seededScrapeCache(string $key, array $cached): ScrapeCache
{
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->with('get', ['metrics:scrape:'.$key])->andReturn(json_encode($cached));
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->with('metrics')->andReturn($connection);

    return new ScrapeCache($factory, 'metrics');
}

function collectedExposition(callable $collect): string
{
    $registry = new CollectorRegistry(new InMemory, false);
    $collect($registry);

    return (new RenderTextFormat)->render($registry->getMetricFamilySamples());
}

function reverbConfig(array $override = []): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => array_replace_recursive(['key' => 'k', 'secret' => 's', 'app_id' => '1', 'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http']], $override),
    ]);
}

it('QueueGauges exposes the snapshot per queue, keeps zeros and omits unreadable (null) values', function (): void {
    $cache = seededScrapeCache(QueueGauges::CACHE_KEY, [
        'queues' => [
            'orders' => ['pending' => 12, 'delayed' => 0, 'reserved' => 3, 'oldest_pending_age_seconds' => null, 'estimated_wait_seconds' => 4.5],
            'chat' => ['pending' => null, 'delayed' => null, 'reserved' => null, 'oldest_pending_age_seconds' => null, 'estimated_wait_seconds' => null],
        ],
        'failed' => ['stored' => 2, 'horizon_recent' => null],
    ]);
    $collector = new QueueGauges(new QueueMetrics(app('queue'), app()), $cache, 5);

    $body = collectedExposition($collector->collect(...));

    expect($body)->toContain('aghigh_queue_pending_jobs{queue="orders"} 12')
        ->and($body)->toContain('aghigh_queue_delayed_jobs{queue="orders"} 0')
        ->and($body)->toContain('aghigh_queue_reserved_jobs{queue="orders"} 3')
        ->and($body)->toContain('aghigh_queue_estimated_wait_seconds{queue="orders"} 4.5')
        ->and($body)->not->toContain('aghigh_queue_oldest_pending_age_seconds')
        ->and($body)->not->toContain('queue="chat"') // unreadable is absent, never a fake 0
        ->and($body)->toContain('aghigh_queue_failed_jobs_stored 2')
        ->and($body)->not->toContain('aghigh_queue_horizon_recent_failed_jobs');
});

it('QueueGauges answers without throwing when the queue backend cannot be read', function (): void {
    $collector = new QueueGauges(new QueueMetrics(app('queue'), app()), new ScrapeCache(app(RedisFactory::class), null), 0);

    expect(fn () => collectedExposition($collector->collect(...)))->not->toThrow(Throwable::class);
});

it('ReverbGauges exposes nothing when broadcasting is not Reverb or the credentials are missing', function (): void {
    $collector = new ReverbGauges(new ScrapeCache(app(RedisFactory::class), null), 0, 0.2);

    config(['broadcasting.default' => 'null']);
    expect(trim(collectedExposition($collector->collect(...))))->toBe('');

    reverbConfig(['secret' => '']);
    expect(trim(collectedExposition($collector->collect(...))))->toBe('');
});

it('ReverbGauges reports up=1 and the connection count from a cached successful call', function (): void {
    reverbConfig();
    $collector = new ReverbGauges(seededScrapeCache(ReverbGauges::CACHE_KEY, ['up' => true, 'connections' => 7]), 10, 1.0);

    $body = collectedExposition($collector->collect(...));

    expect($body)->toContain('reverb_scrape_up 1')->and($body)->toContain('reverb_pusher_api_connections 7');
});

it('ReverbGauges reports up=0 and omits the connection count after a failed call', function (): void {
    reverbConfig();
    $collector = new ReverbGauges(seededScrapeCache(ReverbGauges::CACHE_KEY, ['up' => false, 'error' => 'ConnectException']), 10, 1.0);

    $body = collectedExposition($collector->collect(...));

    expect($body)->toContain('reverb_scrape_up 0')->and($body)->not->toContain('reverb_pusher_api_connections');
});

it('ReverbGauges treats an unreachable Reverb as up=0 within the timeout and never throws', function (): void {
    reverbConfig(); // 127.0.0.1:1: nothing listens
    $collector = new ReverbGauges(new ScrapeCache(app(RedisFactory::class), null), 0, 0.2);

    $started = microtime(true);
    $body = collectedExposition($collector->collect(...));

    expect($body)->toContain('reverb_scrape_up 0')->and($body)->not->toContain('reverb_pusher_api_connections')
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});
