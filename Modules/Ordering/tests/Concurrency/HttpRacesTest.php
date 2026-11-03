<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Actors;
use Tests\Support\IdentityTesting;
use Tests\Support\OrderingTesting;
use Tests\Support\Race;
use Tests\Support\StockInvariants;

// Real Redis state; also exports the JWT keys so the worker processes verify the tokens issued here.
beforeEach(fn () => IdentityTesting::configure(false));

function raceBody(string $product, int $quantity): string
{
    return json_encode(['items' => [['product_id' => $product, 'quantity' => $quantity]]], JSON_THROW_ON_ERROR);
}

/** @param list<array<string, mixed>> $results */
function raceCount(array $results, int $status): int
{
    return count(array_filter($results, fn (array $r): bool => $r['status'] === $status));
}

it('places one order when many HTTP requests race on one Idempotency-Key', function (): void {
    $product = OrderingTesting::product(10, ['price_minor' => 1000]);
    $customer = Actors::user();
    $key = OrderingTesting::key();

    $results = Race::run(__DIR__.'/http-worker.php', array_fill(0, 8, ['POST', '/api/v1/orders', $customer['token'], raceBody($product, 2), $key]));

    expect(array_unique(array_column($results, 'status')))->toBe([201])
        ->and(array_unique(array_column($results, 'order_id')))->toHaveCount(1)
        ->and(count(array_filter($results, fn (array $r): bool => $r['replayed'] === false)))->toBe(1)
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('status', 'completed')->count())->toBe(1)
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(2)
        ->and(StockInvariants::violations())->toBe([]);
});

it('rejects a different payload on a key that is in flight, over HTTP', function (): void {
    $product = OrderingTesting::product(10);
    $customer = Actors::user();
    $key = OrderingTesting::key();
    $workers = [];
    foreach ([1, 1, 1, 1, 2, 2, 2, 2] as $quantity) {
        $workers[] = ['POST', '/api/v1/orders', $customer['token'], raceBody($product, $quantity), $key];
    }

    $results = Race::run(__DIR__.'/http-worker.php', $workers);

    $failed = array_values(array_filter($results, fn (array $r): bool => $r['status'] !== 201));
    expect(raceCount($results, 201))->toBe(4)
        ->and(raceCount($results, 422))->toBe(4)
        ->and(array_values(array_unique(array_column($failed, 'problem'))))->toBe(['idempotency-key-reuse'])
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(StockInvariants::violations())->toBe([]);
});

it('gives the last units to exactly as many users as there is stock, over HTTP', function (): void {
    $product = OrderingTesting::product(3);
    $workers = array_map(
        fn (): array => ['POST', '/api/v1/orders', Actors::user()['token'], raceBody($product, 1), OrderingTesting::key()],
        range(1, 10),
    );

    $results = Race::run(__DIR__.'/http-worker.php', $workers);

    $failed = array_values(array_filter($results, fn (array $r): bool => $r['status'] !== 201));
    expect(raceCount($results, 201))->toBe(3)
        ->and(raceCount($results, 409))->toBe(7)
        ->and(array_values(array_unique(array_column($failed, 'problem'))))->toBe(['out-of-stock'])
        ->and(DB::table('orders')->count())->toBe(3)
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 3, 'available' => 0, 'reserved' => 3, 'sold' => 0])
        ->and(StockInvariants::violations())->toBe([]);
});

it('cancels once when many HTTP requests race to cancel the same order', function (): void {
    $product = OrderingTesting::product(10);
    $customer = Actors::user();
    $orderId = (string) OrderingTesting::place($customer['id'], OrderingTesting::key(), [['product_id' => $product, 'quantity' => 4]])->orderId;

    $results = Race::run(__DIR__.'/http-worker.php', array_fill(0, 8, ['POST', '/api/v1/orders/'.$orderId.'/cancel', $customer['token']]));

    $failed = array_values(array_filter($results, fn (array $r): bool => $r['status'] !== 200));
    expect(raceCount($results, 200))->toBe(1)
        ->and(raceCount($results, 409))->toBe(7)
        ->and(array_values(array_unique(array_column($failed, 'problem'))))->toBe(['invalid-order-transition'])
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('cancelled')
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0])
        ->and(StockInvariants::violations())->toBe([]);
});

it('answers 409 idempotency-in-progress with Retry-After while the key stays locked, then succeeds', function (): void {
    $product = OrderingTesting::product(10);
    $customer = Actors::user();
    $key = OrderingTesting::key();
    $request = ['POST', '/api/v1/orders', $customer['token'], raceBody($product, 1), $key];

    // Another transaction holds the (user_id, key) row, uncommitted, like a request still in flight.
    $holder = DB::connectUsing('lock-holder', (array) config('database.connections.'.config('database.default')), true);
    $holder->beginTransaction();
    try {
        $holder->table('idempotency_keys')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $customer['id'], 'key' => $key,
            'request_hash' => str_repeat('0', 64), 'status' => 'in_progress', 'created_at' => now(),
        ]);

        $blocked = Race::run(__DIR__.'/http-worker.php', [$request], environment: ['ORDERING_IDEMPOTENCY_LOCK_TIMEOUT_MS' => '300']);
    } finally {
        $holder->rollBack();
    }

    expect($blocked[0]['status'])->toBe(409)
        ->and($blocked[0]['problem'])->toBe('idempotency-in-progress')
        ->and($blocked[0]['retry_after'])->toBe('1')
        ->and(DB::table('orders')->count())->toBe(0);

    $retry = Race::run(__DIR__.'/http-worker.php', [$request]);

    expect($retry[0]['status'])->toBe(201)
        ->and($retry[0]['replayed'])->toBeFalse()
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(StockInvariants::violations())->toBe([]);
});
