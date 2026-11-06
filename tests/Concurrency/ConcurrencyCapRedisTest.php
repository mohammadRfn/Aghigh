<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Modules\Shared\Http\Middleware\ConcurrencyCap;
use Modules\Shared\RateLimiting\RedisConcurrencyLimiter;
use Tests\Support\Race;
use Tests\Support\RateLimitTesting;

beforeEach(function (): void {
    RateLimitTesting::useRedis();
    Redis::connection('state')->flushdb();
});

function luaSemaphore(): RedisConcurrencyLimiter
{
    return new RedisConcurrencyLimiter(app('redis'), 'state');
}

it('never lets parallel processes exceed the cap and releases every slot afterwards', function (): void {
    $key = 'cap:'.bin2hex(random_bytes(4));
    $results = Race::run(base_path('tests/Concurrency/limiter-worker.php'), array_fill(0, 20, ['hold', $key, 3, 10_000, 300]));

    $acquired = array_values(array_filter($results, fn (array $r): bool => $r['ok'] && $r['acquired'] === true));
    expect($results)->toHaveCount(20)->and($acquired)->toHaveCount(3);
    foreach ($acquired as $r) {
        expect($r['observed_in_flight'])->toBeLessThanOrEqual(3)->toBeGreaterThanOrEqual(1);
    }
    expect(luaSemaphore()->inFlight($key))->toBe(0);
});

it('releases the slot when the handler throws, and only the owner\'s slot', function (): void {
    config(['rate-limits.concurrency.orders' => ['max' => 2, 'ttl_ms' => 10_000, 'fail' => 'closed']]);
    $semaphore = luaSemaphore();
    $key = 'cc-orders|anon:127.0.0.1'; // no authenticated user on a bare Request -> anon:<ip>
    expect($semaphore->acquire($key, 'someone-else', 2, 10_000))->toBeTrue();

    $middleware = app(ConcurrencyCap::class);
    $request = Request::create('/api/v1/orders', 'POST');
    $request->attributes->set('request_id', 'req-crash');
    try {
        $middleware->handle($request, fn () => throw new RuntimeException('boom'), 'orders');
    } catch (RuntimeException) {
    }
    expect($semaphore->inFlight($key))->toBe(1); // ours released, the other one untouched
    expect((array) Redis::connection('state')->zrange(RedisConcurrencyLimiter::key($key), 0, -1))->toBe(['someone-else']);
});

it('expires a slot leaked by a crashed worker', function (): void {
    $semaphore = luaSemaphore();
    expect($semaphore->acquire('leak', 'dead-worker', 1, 400))->toBeTrue();
    expect($semaphore->acquire('leak', 'next', 1, 400))->toBeFalse();
    usleep(500_000);
    expect($semaphore->acquire('leak', 'next', 1, 400))->toBeTrue();
    $semaphore->release('leak', 'dead-worker'); // idempotent on a vanished member
    expect($semaphore->inFlight('leak'))->toBe(1);
});

it('recovers from NOSCRIPT after SCRIPT FLUSH', function (): void {
    $semaphore = luaSemaphore();
    expect($semaphore->acquire('ns', 'a', 2, 5_000))->toBeTrue();
    Redis::connection('state')->script('flush');
    expect($semaphore->acquire('ns', 'b', 2, 5_000))->toBeTrue();
    expect($semaphore->acquire('ns', 'c', 2, 5_000))->toBeFalse();
});
