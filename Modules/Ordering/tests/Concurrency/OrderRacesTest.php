<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrderingTesting;
use Tests\Support\Race;
use Tests\Support\StockInvariants;

it('places exactly one order for a key used by many simultaneous requests', function (): void {
    $product = OrderingTesting::product(10, ['price_minor' => 1000]);
    $user = (string) Str::uuid7();
    $key = OrderingTesting::key();

    $results = Race::run(__DIR__.'/ordering-worker.php', array_fill(0, 8, ['place', $user, $key, $product, 2]));

    expect(array_values(array_filter($results, fn (array $r): bool => ! $r['ok'])))->toBe([])
        ->and(array_unique(array_column($results, 'order_id')))->toHaveCount(1)
        ->and(array_unique(array_column($results, 'status')))->toBe([201])
        ->and(count(array_filter($results, fn (array $r): bool => $r['replayed'] === false)))->toBe(1)
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('status', 'completed')->count())->toBe(1)
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(2)
        ->and(StockInvariants::violations())->toBe([]);
});

it('rejects a different payload on a key that is already in flight', function (): void {
    $product = OrderingTesting::product(10);
    $user = (string) Str::uuid7();
    $key = OrderingTesting::key();

    $results = Race::run(__DIR__.'/ordering-worker.php', array_merge(
        array_fill(0, 4, ['place', $user, $key, $product, 1]),
        array_fill(0, 4, ['place', $user, $key, $product, 2]),
    ));

    $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    expect($succeeded)->toHaveCount(4)
        ->and($failed)->toHaveCount(4)
        ->and(array_values(array_unique(array_column($failed, 'error'))))->toBe(['idempotency-key-reuse'])
        ->and(array_unique(array_column($succeeded, 'order_id')))->toHaveCount(1)
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(StockInvariants::violations())->toBe([]);
});

it('gives the last units to exactly as many users as there is stock', function (): void {
    $product = OrderingTesting::product(3);
    $workers = array_map(
        fn (): array => ['place', (string) Str::uuid7(), OrderingTesting::key(), $product, 1],
        range(1, 10),
    );

    $results = Race::run(__DIR__.'/ordering-worker.php', $workers);

    $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    expect(count($results) - count($failed))->toBe(3)
        ->and(array_values(array_unique(array_column($failed, 'error'))))->toBe(['out-of-stock'])
        ->and(DB::table('orders')->count())->toBe(3)
        ->and(DB::table('orders')->where('status', 'reserved')->count())->toBe(3)
        ->and(DB::table('idempotency_keys')->count())->toBe(3)
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 3, 'available' => 0, 'reserved' => 3, 'sold' => 0])
        ->and(StockInvariants::violations())->toBe([]);
});

it('cancels an order once and releases its stock once', function (): void {
    $product = OrderingTesting::product(10);
    $orderId = (string) OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => $product, 'quantity' => 4]])->orderId;

    $results = Race::run(__DIR__.'/ordering-worker.php', array_fill(0, 8, ['cancel', $orderId]));

    $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    expect(count($results) - count($failed))->toBe(1)
        ->and(array_values(array_unique(array_column($failed, 'error'))))->toBe(['invalid-order-transition'])
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('cancelled')
        ->and((int) DB::table('orders')->where('id', $orderId)->value('version'))->toBe(3)
        ->and(DB::table('stock_reservations')->where('order_id', $orderId)->pluck('status')->unique()->values()->all())->toBe(['released'])
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0])
        ->and(StockInvariants::violations())->toBe([]);
});

it('lets exactly one of a racing cancel and pay win and keeps the stock consistent with the winner', function (): void {
    $product = OrderingTesting::product(10);
    $orderId = (string) OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => $product, 'quantity' => 4]])->orderId;

    $results = Race::run(__DIR__.'/ordering-worker.php', array_merge(
        array_fill(0, 6, ['cancel', $orderId]),
        array_fill(0, 6, ['pay', $orderId]),
    ));

    $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    $status = DB::table('orders')->where('id', $orderId)->value('status');
    expect(count($results) - count($failed))->toBe(1)
        ->and(array_values(array_unique(array_column($failed, 'error'))))->toBe(['invalid-order-transition'])
        ->and($status)->toBeIn(['cancelled', 'paid'])
        ->and((int) DB::table('orders')->where('id', $orderId)->value('version'))->toBe(3)
        ->and(OrderingTesting::counters($product))->toBe($status === 'cancelled'
            ? ['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0]
            : ['initial' => 10, 'available' => 6, 'reserved' => 0, 'sold' => 4])
        ->and(DB::table('stock_reservations')->where('order_id', $orderId)->pluck('status')->unique()->values()->all())
        ->toBe([$status === 'cancelled' ? 'released' : 'committed'])
        ->and(StockInvariants::violations())->toBe([]);
});
