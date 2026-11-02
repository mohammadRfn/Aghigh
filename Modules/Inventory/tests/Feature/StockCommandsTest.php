<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

it('sets stock through inventory:set-stock', function (): void {
    $product = OrderingTesting::product();

    $this->artisan('inventory:set-stock', ['product' => $product, 'quantity' => '25'])->assertExitCode(0);

    expect(OrderingTesting::counters($product))->toBe(['initial' => 25, 'available' => 25, 'reserved' => 0, 'sold' => 0]);
});

it('fails for an unknown product', function (): void {
    $this->artisan('inventory:set-stock', ['product' => '00000000-0000-7000-8000-000000000001', 'quantity' => '5'])
        ->expectsOutputToContain('Unknown product')
        ->assertExitCode(1);
});

it('rejects quantities that are not non-negative integers', function (string $quantity): void {
    $product = OrderingTesting::product();

    $this->artisan('inventory:set-stock', ['product' => $product, 'quantity' => $quantity])->assertExitCode(1);

    expect(DB::table('stock_items')->count())->toBe(0);
})->with(['abc', '1.5', '-1', '1000000000000']);

it('refuses a level below the allocated units', function (): void {
    $product = OrderingTesting::product();
    OrderingTesting::stock($product, 10, reserved: 3, sold: 3);

    $this->artisan('inventory:set-stock', ['product' => $product, 'quantity' => '5'])
        ->expectsOutputToContain('below')
        ->assertExitCode(1);
});

it('seeds every product with the same stock level', function (): void {
    $products = [OrderingTesting::product(), OrderingTesting::product(), OrderingTesting::product()];

    $this->artisan('inventory:seed-stock', ['--quantity' => 7])->expectsOutputToContain('Stock set to 7 for 3 products.')->assertExitCode(0);

    foreach ($products as $product) {
        expect(OrderingTesting::counters($product))->toBe(['initial' => 7, 'available' => 7, 'reserved' => 0, 'sold' => 0]);
    }
});

it('keeps reserved and sold when reseeding an existing row', function (): void {
    $product = OrderingTesting::product();
    OrderingTesting::stock($product, 10, reserved: 3, sold: 2);

    $this->artisan('inventory:seed-stock', ['--quantity' => 20])->assertExitCode(0);

    expect(OrderingTesting::counters($product))->toBe(['initial' => 20, 'available' => 15, 'reserved' => 3, 'sold' => 2]);
});

it('refuses to seed outside local, testing and loadtest', function (): void {
    OrderingTesting::product();
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('inventory:seed-stock', ['--quantity' => 7])->expectsOutputToContain('runs only in local')->assertExitCode(1);

    expect(DB::table('stock_items')->count())->toBe(0);
});

it('rejects an out-of-range seed quantity', function (): void {
    $this->artisan('inventory:seed-stock', ['--quantity' => -1])->assertExitCode(1);
});
