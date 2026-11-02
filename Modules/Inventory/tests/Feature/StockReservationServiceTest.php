<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Exceptions\OutOfStock;
use Modules\Inventory\Services\StockReservationService;
use Modules\Inventory\Strategies\AtomicStockStrategy;
use Modules\Inventory\Strategies\LockingStockStrategy;
use Modules\Inventory\Strategies\NaiveStockStrategy;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

dataset('stock strategies', [
    'atomic' => [AtomicStockStrategy::class],
    'locking' => [LockingStockStrategy::class],
    'naive' => [NaiveStockStrategy::class],
]);

const LOW_PRODUCT = '00000000-0000-7000-8000-000000000001';
const HIGH_PRODUCT = 'ffffffff-ffff-7fff-8fff-ffffffffffff';

it('reserves every product and writes one active reservation per product', function (string $strategy): void {
    $service = new StockReservationService(new $strategy);
    [$a, $b, $order] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($a, 10);
    OrderingTesting::stock($b, 4);

    DB::transaction(fn () => $service->reserve($order, [$a => 3, $b => 4], CarbonImmutable::now()->addMinutes(15)));

    expect(OrderingTesting::counters($a))->toBe(['initial' => 10, 'available' => 7, 'reserved' => 3, 'sold' => 0])
        ->and(OrderingTesting::counters($b))->toBe(['initial' => 4, 'available' => 0, 'reserved' => 4, 'sold' => 0])
        ->and(DB::table('stock_reservations')->where('order_id', $order)->where('status', 'active')->count())->toBe(2);
})->with('stock strategies');

it('reports the first insufficient product in ascending product id order', function (string $strategy): void {
    $service = new StockReservationService(new $strategy);
    OrderingTesting::stock(LOW_PRODUCT, 1);
    OrderingTesting::stock(HIGH_PRODUCT, 1);

    $error = OrderingTesting::thrown(fn () => DB::transaction(fn () => $service->reserve(
        (string) Str::uuid7(), [HIGH_PRODUCT => 5, LOW_PRODUCT => 5], CarbonImmutable::now()->addMinutes(15),
    )));

    expect($error)->toBeInstanceOf(OutOfStock::class)
        ->and($error->problemStatus())->toBe(409)
        ->and($error->problemType())->toBe('out-of-stock')
        ->and($error->problemExtensions())->toBe(['product_ids' => [LOW_PRODUCT]]);
})->with('stock strategies');

it('treats a missing stock row as out of stock', function (string $strategy): void {
    $service = new StockReservationService(new $strategy);

    $error = OrderingTesting::thrown(fn () => DB::transaction(fn () => $service->reserve(
        (string) Str::uuid7(), [LOW_PRODUCT => 1], CarbonImmutable::now()->addMinutes(15),
    )));

    expect($error)->toBeInstanceOf(OutOfStock::class);
})->with('stock strategies');

it('leaves no partial reservation when a later product runs out', function (string $strategy): void {
    $service = new StockReservationService(new $strategy);
    OrderingTesting::stock(LOW_PRODUCT, 5);
    OrderingTesting::stock(HIGH_PRODUCT, 1);

    $error = OrderingTesting::thrown(fn () => DB::transaction(fn () => $service->reserve(
        (string) Str::uuid7(), [LOW_PRODUCT => 2, HIGH_PRODUCT => 3], CarbonImmutable::now()->addMinutes(15),
    )));

    expect($error)->toBeInstanceOf(OutOfStock::class)
        ->and(OrderingTesting::counters(LOW_PRODUCT))->toBe(['initial' => 5, 'available' => 5, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('stock_reservations')->count())->toBe(0);
})->with('stock strategies');

it('merges duplicate and mixed case product ids into one reservation', function (): void {
    $service = new StockReservationService(new AtomicStockStrategy);
    $id = 'abcdef01-2345-7678-89ab-cdef01234567';
    OrderingTesting::stock($id, 10);

    DB::transaction(fn () => $service->reserve((string) Str::uuid7(), [strtoupper($id) => 1, $id => 2], CarbonImmutable::now()->addMinutes(15)));

    expect(OrderingTesting::counters($id)['reserved'])->toBe(3)
        ->and(DB::table('stock_reservations')->count())->toBe(1)
        ->and((int) DB::table('stock_reservations')->value('quantity'))->toBe(3)
        ->and(DB::table('stock_reservations')->value('product_id'))->toBe($id);
});

it('rejects empty and malformed reservations', function (array $quantities): void {
    $service = new StockReservationService(new AtomicStockStrategy);

    expect(fn () => DB::transaction(fn () => $service->reserve((string) Str::uuid7(), $quantities, CarbonImmutable::now()->addMinute())))
        ->toThrow(LogicException::class);
})->with([
    'empty' => [[]],
    'non-uuid product' => [['not-a-uuid' => 1]],
    'zero quantity' => [[LOW_PRODUCT => 0]],
    'negative quantity' => [[LOW_PRODUCT => -2]],
]);

it('releases active reservations back to available and is idempotent', function (): void {
    $service = new StockReservationService(new AtomicStockStrategy);
    [$a, $b, $order] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($a, 10);
    OrderingTesting::stock($b, 5);
    DB::transaction(fn () => $service->reserve($order, [$a => 3, $b => 2], CarbonImmutable::now()->addMinutes(15)));

    expect($service->release($order))->toBe(2)
        ->and(OrderingTesting::counters($a))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0])
        ->and(OrderingTesting::counters($b))->toBe(['initial' => 5, 'available' => 5, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('stock_reservations')->where('status', 'released')->count())->toBe(2)
        ->and($service->release($order))->toBe(0)
        ->and(OrderingTesting::counters($a)['available'])->toBe(10);
});

it('commits reservations into sold and is idempotent', function (): void {
    $service = new StockReservationService(new AtomicStockStrategy);
    [$a, $order] = [(string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($a, 10);
    DB::transaction(fn () => $service->reserve($order, [$a => 4], CarbonImmutable::now()->addMinutes(15)));

    expect($service->commit($order))->toBe(1)
        ->and(OrderingTesting::counters($a))->toBe(['initial' => 10, 'available' => 6, 'reserved' => 0, 'sold' => 4])
        ->and($service->commit($order))->toBe(0)
        ->and(OrderingTesting::counters($a)['sold'])->toBe(4);
});

it('cannot release a reservation that was already committed', function (): void {
    $service = new StockReservationService(new AtomicStockStrategy);
    [$a, $order] = [(string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($a, 10);
    DB::transaction(fn () => $service->reserve($order, [$a => 4], CarbonImmutable::now()->addMinutes(15)));
    $service->commit($order);

    expect($service->release($order))->toBe(0)
        ->and(OrderingTesting::counters($a))->toBe(['initial' => 10, 'available' => 6, 'reserved' => 0, 'sold' => 4]);
});

it('expires only overdue active reservations and returns each order id once', function (): void {
    $service = new StockReservationService(new AtomicStockStrategy);
    [$a, $b, $overdue, $fresh] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($a, 10);
    OrderingTesting::stock($b, 10);
    DB::transaction(fn () => $service->reserve($overdue, [$a => 2, $b => 1], CarbonImmutable::now()->subMinute()));
    DB::transaction(fn () => $service->reserve($fresh, [$a => 1], CarbonImmutable::now()->addMinutes(15)));

    expect($service->releaseExpired(100))->toBe([$overdue])
        ->and(OrderingTesting::counters($a))->toBe(['initial' => 10, 'available' => 9, 'reserved' => 1, 'sold' => 0])
        ->and(OrderingTesting::counters($b))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('stock_reservations')->where('order_id', $overdue)->pluck('status')->unique()->values()->all())->toBe(['expired'])
        ->and(DB::table('stock_reservations')->where('order_id', $fresh)->value('status'))->toBe('active')
        ->and($service->releaseExpired(100))->toBe([]);
});

it('honours the limit when expiring', function (): void {
    $service = new StockReservationService(new AtomicStockStrategy);
    $product = (string) Str::uuid7();
    OrderingTesting::stock($product, 10);
    foreach (range(1, 3) as $ignored) {
        DB::transaction(fn () => $service->reserve((string) Str::uuid7(), [$product => 1], CarbonImmutable::now()->subMinute()));
    }

    expect($service->releaseExpired(1))->toHaveCount(1)
        ->and(DB::table('stock_reservations')->where('status', 'active')->count())->toBe(2)
        ->and($service->releaseExpired(10))->toHaveCount(2)
        ->and(OrderingTesting::counters($product)['available'])->toBe(10);
});

it('keeps the balance invariant through a mixed sequence', function (): void {
    $service = new StockReservationService(new AtomicStockStrategy);
    $product = (string) Str::uuid7();
    [$o1, $o2, $o3, $o4] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($product, 20);
    DB::transaction(fn () => $service->reserve($o1, [$product => 5], CarbonImmutable::now()->addMinutes(15)));
    DB::transaction(fn () => $service->reserve($o2, [$product => 4], CarbonImmutable::now()->addMinutes(15)));
    DB::transaction(fn () => $service->reserve($o3, [$product => 3], CarbonImmutable::now()->subMinute()));
    DB::transaction(fn () => $service->reserve($o4, [$product => 2], CarbonImmutable::now()->addMinutes(15)));

    $service->release($o1);
    $service->commit($o2);

    expect($service->releaseExpired(10))->toBe([$o3])
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 20, 'available' => 14, 'reserved' => 2, 'sold' => 4])
        ->and(OrderingTesting::balanced($product))->toBeTrue();
});
