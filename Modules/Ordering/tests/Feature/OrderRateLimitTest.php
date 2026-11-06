<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Ordering\Http\Middleware\RequireIdempotencyKey;
use Modules\Shared\Contracts\ProblemException;
use Modules\Shared\Events\RateLimitHit;
use Modules\Shared\Exceptions\RateLimitExceeded;
use Modules\Shared\Http\Middleware\ConcurrencyCap;
use Modules\Shared\Http\Middleware\ThrottleSliding;
use Tests\Support\Actors;
use Tests\Support\OrderingTesting;
use Tests\Support\RateLimitTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
    RateLimitTesting::exact(['orders-ip' => 100, 'orders' => 100], ordersPerMinute: 100, concurrency: 5);
});

function orderUser(): array
{
    $actor = Actors::user();

    return [$actor['id'], $actor['token']];
}

it('orders the middleware: per-IP limiter -> auth -> per-user limiter -> concurrency cap -> idempotency', function (): void {
    $route = Route::getRoutes()->getByName('api.v1.ordering.orders.store');
    $resolved = array_values(app(Router::class)->gatherRouteMiddleware($route)); // aliases expanded, priority applied
    $auth = in_array('auth:api', $resolved, true) ? 'auth:api' : Authenticate::class.':api'; // alias is expanded only after the kernel ran
    $position = function (string $exact) use ($resolved): int {
        $index = array_search($exact, $resolved, true);
        expect($index)->not->toBeFalse("middleware {$exact} missing in ".implode(', ', $resolved));

        return (int) $index;
    };

    expect($position(ThrottleSliding::class.':orders-ip'))->toBeLessThan($position($auth))
        ->and($position($auth))->toBeLessThan($position(ThrottleSliding::class.':orders'))
        ->and($position(ThrottleSliding::class.':orders'))->toBeLessThan($position(ConcurrencyCap::class.':orders'))
        ->and($position(ConcurrencyCap::class.':orders'))->toBeLessThan($position(RequireIdempotencyKey::class));
});

it('answers 429 before auth from a hammering IP and never stores an Idempotency-Key', function (): void {
    RateLimitTesting::exact(['orders-ip' => 1]);
    [, $token] = orderUser();
    $product = OrderingTesting::product(10, ['price_minor' => 100]);
    $items = ['items' => [['product_id' => $product, 'quantity' => 1]]];

    $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'first-key-00000001'])->assertCreated()->assertHeader('RateLimit-Remaining', '0');
    $response = $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'second-key-00000002'])->assertStatus(429);

    expect($response->json('type'))->toEndWith('/problems/too-many-requests')
        ->and(DB::table('idempotency_keys')->where('key', 'second-key-00000002')->exists())->toBeFalse()
        ->and(DB::table('idempotency_keys')->count())->toBe(1)
        ->and(DB::table('orders')->count())->toBe(1);

    // The same key is still usable once the limiter allows the request again.
    RateLimitTesting::exact(['orders-ip' => 100]);
    $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'second-key-00000002'])->assertCreated();
});

it('rejects with 429 from the per-user sliding limiter without touching idempotency rows', function (): void {
    RateLimitTesting::exact(['orders' => 1]);
    [, $token] = orderUser();
    $this->withToken($token)->getJson('/api/v1/orders')->assertOk()->assertHeader('RateLimit-Limit', '1');
    $this->withToken($token)->postJson('/api/v1/orders', ['items' => []], ['Idempotency-Key' => 'blocked-key-0003'])->assertStatus(429);
    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

it('business limit: replays do not consume the orders-per-minute budget', function (): void {
    RateLimitTesting::exact(ordersPerMinute: 2);
    Event::fake([RateLimitHit::class]);
    [, $token] = orderUser();
    $product = OrderingTesting::product(10, ['price_minor' => 100]);
    $items = ['items' => [['product_id' => $product, 'quantity' => 1]]];

    $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-key-000001'])->assertCreated();
    foreach (range(1, 5) as $_) { // replays: same key, same payload -> free
        $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-key-000001'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    }
    $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-key-000002'])->assertCreated();

    $response = $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-key-000003'])->assertStatus(429)
        ->assertHeader('Retry-After')->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '0');
    expect($response->json('type'))->toEndWith('/problems/orders-per-minute-exceeded')
        ->and(DB::table('idempotency_keys')->where('key', 'replay-key-000003')->exists())->toBeFalse();
    Event::assertDispatched(RateLimitHit::class, fn (RateLimitHit $e): bool => $e->limiter === 'business:orders-per-minute' && $e->driver === 'business');
});

it('business limit: a replay answered from the database (cache emptied) does not consume budget', function (): void {
    RateLimitTesting::exact(ordersPerMinute: 2);
    [, $token] = orderUser();
    $product = OrderingTesting::product(10, ['price_minor' => 100]);
    $items = ['items' => [['product_id' => $product, 'quantity' => 1]]];

    $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-db-key-00001'])->assertCreated();
    foreach (range(1, 5) as $_) {
        Cache::store('state')->flush(); // cache miss: the replay comes from idempotency_keys
        $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-db-key-00001'])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    }

    // Only ONE unit of the budget (2) was spent by the first order, so exactly one more order fits.
    $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-db-key-00002'])->assertCreated();
    $response = $this->withToken($token)->postJson('/api/v1/orders', $items, ['Idempotency-Key' => 'replay-db-key-00003'])->assertStatus(429);
    expect($response->json('type'))->toEndWith('/problems/orders-per-minute-exceeded')
        ->and(DB::table('orders')->count())->toBe(2);
});

it('business limit: an attempt rejected with 409 out-of-stock DOES consume budget', function (): void {
    RateLimitTesting::exact(ordersPerMinute: 2);
    $userId = (string) Str::uuid7();
    $product = OrderingTesting::product(0, ['price_minor' => 100]); // nothing in stock
    OrderingTesting::stock($product, 0);

    foreach (range(1, 2) as $_) {
        $error = OrderingTesting::thrown(fn () => OrderingTesting::place($userId, OrderingTesting::key(), [['product_id' => $product, 'quantity' => 1]]));
        expect($error)->toBeInstanceOf(ProblemException::class)->and($error->problemStatus())->toBe(409);
    }
    $third = OrderingTesting::thrown(fn () => OrderingTesting::place($userId, OrderingTesting::key(), [['product_id' => $product, 'quantity' => 1]]));
    expect($third)->toBeInstanceOf(RateLimitExceeded::class)->and($third->problemType())->toBe('orders-per-minute-exceeded');
    expect(DB::table('orders')->count())->toBe(0);
});

it('business limit is per user', function (): void {
    RateLimitTesting::exact(ordersPerMinute: 1);
    $product = OrderingTesting::product(10, ['price_minor' => 100]);
    $items = [['product_id' => $product, 'quantity' => 1]];
    expect(OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), $items)->status)->toBe(201);
    expect(OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), $items)->status)->toBe(201);
});

it('concurrency cap: a held slot of the same user yields 429 concurrency-limit-exceeded and stores no key', function (): void {
    RateLimitTesting::exact(concurrency: 1);
    [$userId, $token] = orderUser();
    RateLimitTesting::memorySemaphore()->acquire('cc-orders|user:'.$userId, 'in-flight-elsewhere', 1, 30_000);

    $response = $this->withToken($token)->postJson('/api/v1/orders', ['items' => []], ['Idempotency-Key' => 'cap-key-000000001'])->assertStatus(429);
    expect($response->json('type'))->toEndWith('/problems/concurrency-limit-exceeded')
        ->and(DB::table('idempotency_keys')->count())->toBe(0);
});

it('adds RateLimit headers to successful order responses', function (): void {
    [, $token] = orderUser();
    $this->withToken($token)->getJson('/api/v1/orders')->assertOk()
        ->assertHeader('RateLimit-Limit')->assertHeader('RateLimit-Remaining')->assertHeader('RateLimit-Reset')
        ->assertHeaderMissing('X-RateLimit-Limit');
});
