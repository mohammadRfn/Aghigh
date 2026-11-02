<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Exceptions\InventoryProblem;
use Modules\Inventory\Services\StockLevels;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

it('creates the stock row on first set and makes everything available', function (): void {
    $id = (string) Str::uuid7();

    expect(app(StockLevels::class)->set($id, 50))
        ->toBe(['product_id' => $id, 'initial' => 50, 'available' => 50, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('stock_items')->where('product_id', $id)->count())->toBe(1);
});

it('moves only initial and available and never touches reserved or sold', function (): void {
    $id = (string) Str::uuid7();
    OrderingTesting::stock($id, 10, reserved: 3, sold: 3);

    expect(app(StockLevels::class)->set($id, 8))
        ->toBe(['product_id' => $id, 'initial' => 8, 'available' => 2, 'reserved' => 3, 'sold' => 3])
        ->and(OrderingTesting::balanced($id))->toBeTrue();
});

it('accepts exactly the already allocated amount', function (): void {
    $id = (string) Str::uuid7();
    OrderingTesting::stock($id, 10, reserved: 3, sold: 3);

    expect(app(StockLevels::class)->set($id, 6)['available'])->toBe(0);
});

it('refuses a level below what is already reserved or sold', function (): void {
    $id = (string) Str::uuid7();
    OrderingTesting::stock($id, 10, reserved: 3, sold: 3);

    $problem = OrderingTesting::thrown(fn () => app(StockLevels::class)->set($id, 5));

    expect($problem)->toBeInstanceOf(InventoryProblem::class)
        ->and($problem->problemStatus())->toBe(422)
        ->and($problem->problemType())->toBe('stock-below-allocated')
        ->and($problem->problemExtensions())->toBe(['product_id' => $id, 'reserved' => 3, 'sold' => 3])
        ->and(OrderingTesting::counters($id))->toBe(['initial' => 10, 'available' => 4, 'reserved' => 3, 'sold' => 3]);
});

it('rejects ids that are not UUIDs and levels outside the configured range', function (): void {
    config(['inventory.max_stock' => 100]);
    $id = (string) Str::uuid7();

    foreach ([['not-a-uuid', 5], [$id, -1], [$id, 101]] as [$product, $quantity]) {
        $problem = OrderingTesting::thrown(fn () => app(StockLevels::class)->set($product, $quantity));
        expect($problem)->toBeInstanceOf(InventoryProblem::class)
            ->and($problem->problemStatus())->toBe(422)
            ->and($problem->problemType())->toBe('inventory-validation');
    }

    expect(DB::table('stock_items')->count())->toBe(0)
        ->and(app(StockLevels::class)->set($id, 100)['initial'])->toBe(100);
});

it('reads zeros for a product without a stock row and normalizes the id', function (): void {
    $id = (string) Str::uuid7();

    expect(app(StockLevels::class)->get(strtoupper($id)))
        ->toBe(['product_id' => $id, 'initial' => 0, 'available' => 0, 'reserved' => 0, 'sold' => 0]);
});

it('stores the product id in lower case', function (): void {
    $id = (string) Str::uuid7();

    expect(app(StockLevels::class)->set(strtoupper($id), 5)['product_id'])->toBe($id)
        ->and(DB::table('stock_items')->where('product_id', $id)->count())->toBe(1);
});
