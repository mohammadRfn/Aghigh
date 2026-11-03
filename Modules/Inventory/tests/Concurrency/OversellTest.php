<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\StockReservations;
use Tests\Support\OrderingTesting;
use Tests\Support\Race;
use Tests\Support\StockInvariants;

it('never sells more than the stock under simultaneous reservations', function (string $strategy): void {
    $product = (string) Str::uuid7();
    OrderingTesting::stock($product, 5);

    $results = Race::run(__DIR__.'/inventory-worker.php', array_fill(0, 20, ['reserve', $strategy, $product, 1, 0]));

    $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    expect(count($results) - count($failed))->toBe(5)
        ->and(array_values(array_unique(array_column($failed, 'error'))))->toBe(['out-of-stock'])
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 5, 'available' => 0, 'reserved' => 5, 'sold' => 0])
        ->and(DB::table('stock_reservations')->where('product_id', $product)->where('status', 'active')->count())->toBe(5)
        ->and(StockInvariants::violations())->toBe([]);
})->with(['atomic', 'locking']);

it('sells whole multi-unit lines or nothing', function (string $strategy): void {
    $product = (string) Str::uuid7();
    OrderingTesting::stock($product, 10);

    $results = Race::run(__DIR__.'/inventory-worker.php', array_fill(0, 8, ['reserve', $strategy, $product, 3, 0]));

    $succeeded = count(array_filter($results, fn (array $r): bool => $r['ok']));
    expect($succeeded)->toBe(3)
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 10, 'available' => 1, 'reserved' => 9, 'sold' => 0])
        ->and(StockInvariants::violations())->toBe([]);
})->with(['atomic', 'locking']);

it('does not deadlock when orders list the same products in opposite order', function (string $strategy): void {
    [$a, $b] = [(string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($a, 100);
    OrderingTesting::stock($b, 100);
    $workers = [];
    foreach (range(1, 12) as $i) {
        $workers[] = ['reserve', $strategy, $i % 2 === 0 ? "{$a},{$b}" : "{$b},{$a}", 1, 0];
    }

    $results = Race::run(__DIR__.'/inventory-worker.php', $workers);

    expect(array_values(array_filter($results, fn (array $r): bool => ! $r['ok'])))->toBe([])
        ->and(OrderingTesting::counters($a)['reserved'])->toBe(12)
        ->and(OrderingTesting::counters($b)['reserved'])->toBe(12)
        ->and(StockInvariants::violations())->toBe([]);
})->with(['atomic', 'locking']);

it('loses updates with the naive strategy, which is the reason it exists', function (): void {
    $product = (string) Str::uuid7();
    OrderingTesting::stock($product, 1);

    $results = Race::run(__DIR__.'/inventory-worker.php', array_fill(0, 8, ['reserve', 'naive', $product, 1, 300]));

    expect(count(array_filter($results, fn (array $r): bool => $r['ok'])))->toBeGreaterThan(1)
        ->and(StockInvariants::violations())->toContain('drift:'.$product);
});

it('settles a reservation exactly once however many workers race on it', function (string $mode): void {
    [$a, $b, $order] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($a, 10);
    OrderingTesting::stock($b, 10);
    DB::transaction(fn () => app(StockReservations::class)->reserve($order, [$a => 3, $b => 2], CarbonImmutable::now()->addMinutes(15)));

    $results = Race::run(__DIR__.'/inventory-worker.php', array_fill(0, 10, [$mode, $order]));

    expect(array_values(array_filter($results, fn (array $r): bool => ! $r['ok'])))->toBe([])
        ->and(array_sum(array_column($results, 'settled')))->toBe(2)
        ->and(OrderingTesting::counters($a))->toBe($mode === 'release'
            ? ['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0]
            : ['initial' => 10, 'available' => 7, 'reserved' => 0, 'sold' => 3])
        ->and(StockInvariants::violations())->toBe([]);
})->with(['release', 'commit']);

it('lets exactly one of a racing release and commit win', function (): void {
    [$product, $order] = [(string) Str::uuid7(), (string) Str::uuid7()];
    OrderingTesting::stock($product, 10);
    DB::transaction(fn () => app(StockReservations::class)->reserve($order, [$product => 4], CarbonImmutable::now()->addMinutes(15)));

    $results = Race::run(__DIR__.'/inventory-worker.php', array_merge(array_fill(0, 5, ['release', $order]), array_fill(0, 5, ['commit', $order])));

    $statuses = DB::table('stock_reservations')->where('order_id', $order)->pluck('status')->unique()->values()->all();
    expect(array_values(array_filter($results, fn (array $r): bool => ! $r['ok'])))->toBe([])
        ->and(array_sum(array_column($results, 'settled')))->toBe(1)
        ->and($statuses)->toHaveCount(1)
        ->and($statuses[0])->toBeIn(['released', 'committed'])
        ->and(StockInvariants::violations())->toBe([]);
});
