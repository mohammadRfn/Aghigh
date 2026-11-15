<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery\MockInterface;
use Modules\Shared\Metrics\ScrapeCache;

afterEach(fn () => Mockery::close());

function scrapeCacheRedis(MockInterface $connection): RedisFactory
{
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->with('metrics')->andReturn($connection);

    return $factory;
}

it('is a pass-through without a connection or with a zero TTL (memory/null drivers)', function (): void {
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldNotReceive('connection');

    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return ['n' => $calls];
    };

    expect((new ScrapeCache($factory, null))->remember('k', 5, $compute))->toBe(['n' => 1])
        ->and((new ScrapeCache($factory, 'metrics'))->remember('k', 0, $compute))->toBe(['n' => 2])
        ->and($calls)->toBe(2);
});

it('returns the cached value without computing on a hit', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->once()->with('get', ['metrics:scrape:queues'])->andReturn('{"up":true,"n":7}');
    $connection->shouldNotReceive('command')->with('set', Mockery::any());

    $result = (new ScrapeCache(scrapeCacheRedis($connection), 'metrics'))->remember('queues', 5, fn (): array => throw new LogicException('must not compute on a hit'));

    expect($result)->toBe(['up' => true, 'n' => 7]);
});

it('computes on a miss and stores the JSON with the TTL', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->once()->with('get', ['metrics:scrape:reverb'])->andReturn(null);
    $connection->shouldReceive('command')->once()->with('set', ['metrics:scrape:reverb', '{"up":true,"connections":3}', 'EX', 10])->andReturn(true);

    $result = (new ScrapeCache(scrapeCacheRedis($connection), 'metrics'))->remember('reverb', 10, fn (): array => ['up' => true, 'connections' => 3]);

    expect($result)->toBe(['up' => true, 'connections' => 3]);
});

it('treats a corrupt cached value as a miss', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->with('get', ['metrics:scrape:k'])->andReturn('{not json');
    $connection->shouldReceive('command')->with('set', Mockery::type('array'))->once()->andReturn(true);

    expect((new ScrapeCache(scrapeCacheRedis($connection), 'metrics'))->remember('k', 5, fn (): array => ['fresh' => 1]))->toBe(['fresh' => 1]);
});

it('computes every time and never throws when Redis is broken', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->andThrow(new RuntimeException('redis down'));

    $cache = new ScrapeCache(scrapeCacheRedis($connection), 'metrics');

    expect($cache->remember('k', 5, fn (): array => ['v' => 1]))->toBe(['v' => 1])
        ->and(fn () => $cache->forget('k'))->not->toThrow(Throwable::class);
});

it('forgets a key with DEL and is a no-op without a connection', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->once()->with('del', ['metrics:scrape:queues'])->andReturn(1);
    (new ScrapeCache(scrapeCacheRedis($connection), 'metrics'))->forget('queues');

    $none = Mockery::mock(RedisFactory::class);
    $none->shouldNotReceive('connection');
    (new ScrapeCache($none, null))->forget('queues');

    expect(true)->toBeTrue(); // expectations above are verified by Mockery::close()
});
