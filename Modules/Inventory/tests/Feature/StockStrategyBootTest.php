<?php

declare(strict_types=1);

use Modules\Inventory\Contracts\StockStrategy;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Inventory\Strategies\NaiveStockStrategy;

/*
 | Task 10: the load tests switch STOCK_STRATEGY and STOCK_NAIVE_DELAY_MS per run. Both are deliberately unsafe aids,
 | so outside local/testing/loadtest they must fail the boot (the guard is UnsafeEnvironment, checked in
 | InventoryServiceProvider). RATE_LIMIT_MULTIPLIER is covered by Modules/Shared/tests/Feature/RateLimitMultiplierBootTest.php.
 */

it('refuses to resolve the naive strategy outside local, testing and loadtest', function (string $environment): void {
    config(['inventory.strategy' => 'naive', 'inventory.naive_delay_ms' => 0]);
    app()->detectEnvironment(fn (): string => $environment);
    try {
        expect(fn () => app(StockStrategy::class))->toThrow(InvalidArgumentException::class, 'STOCK_STRATEGY=naive');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
})->with(['production', 'staging']);

it('fails the provider boot with STOCK_STRATEGY=naive in production', function (): void {
    config(['inventory.strategy' => 'naive', 'inventory.naive_delay_ms' => 0]);
    app()->detectEnvironment(fn (): string => 'production');
    try {
        expect(fn () => InventoryServiceProvider::assertSafeConfiguration(app()))->toThrow(InvalidArgumentException::class, 'STOCK_STRATEGY=naive');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('fails the provider boot with STOCK_NAIVE_DELAY_MS in production even for a safe strategy', function (): void {
    config(['inventory.strategy' => 'atomic', 'inventory.naive_delay_ms' => 25]);
    app()->detectEnvironment(fn (): string => 'production');
    try {
        expect(fn () => InventoryServiceProvider::assertSafeConfiguration(app()))->toThrow(InvalidArgumentException::class, 'STOCK_NAIVE_DELAY_MS');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('allows the naive strategy for load tests', function (): void {
    config(['inventory.strategy' => 'naive', 'inventory.naive_delay_ms' => 25]);
    app()->detectEnvironment(fn (): string => 'loadtest');
    try {
        expect(app(StockStrategy::class))->toBeInstanceOf(NaiveStockStrategy::class);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});
