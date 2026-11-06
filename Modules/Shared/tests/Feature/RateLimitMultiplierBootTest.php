<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Modules\Shared\Providers\RateLimitServiceProvider;
use Modules\Shared\RateLimiting\LimiterRegistry;
use Tests\Support\RateLimitTesting;

it('scales configured limits through the registry in testing', function (): void {
    config(['rate-limits.multiplier' => 3, 'rate-limits.limiters.public-catalog.limit' => 10, 'rate-limits.business.orders_per_minute' => 4, 'rate-limits.concurrency.orders.max' => 2]);
    $registry = app(LimiterRegistry::class);
    expect($registry->get('public-catalog')->limit)->toBe(30)
        ->and($registry->business('orders_per_minute'))->toBe(12)
        ->and($registry->concurrency('orders')['max'])->toBe(6);
});

it('caps sliding-log limits at the configured hard cap even with a huge multiplier', function (): void {
    config(['rate-limits.multiplier' => 10000, 'rate-limits.limiters.orders.limit' => 30, 'rate-limits.sliding_max_limit' => 500]);
    expect(app(LimiterRegistry::class)->get('orders')->limit)->toBe(500)
        ->and(app(LimiterRegistry::class)->get('public-catalog')->driver)->toBe('laravel');
});

it('ignores the multiplier outside the allowed environments and fails the provider boot', function (): void {
    config(['rate-limits.multiplier' => 50, 'rate-limits.limiters.public-catalog.limit' => 10]);
    app()->detectEnvironment(fn (): string => 'production');
    try {
        expect(app(LimiterRegistry::class)->get('public-catalog')->limit)->toBe(10);
        expect(fn () => (new RateLimitServiceProvider(app()))->boot(app(Router::class)))->toThrow(RuntimeException::class, 'RATE_LIMIT_MULTIPLIER');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('refuses the in-memory driver outside the allowed environments', function (): void {
    config(['rate-limits.multiplier' => null, 'rate-limits.sliding_driver' => 'memory']);
    app()->detectEnvironment(fn (): string => 'production');
    try {
        expect(fn () => (new RateLimitServiceProvider(app()))->boot(app(Router::class)))->toThrow(RuntimeException::class, 'memory');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('a limiter test can pin exact values with the helper', function (): void {
    RateLimitTesting::exact(['public-catalog' => 2]);
    Route::middleware(['api', 'throttle.sliding:public-catalog'])->get('/api/v1/_t/exact', fn () => response()->json([]));
    $this->getJson('/api/v1/_t/exact')->assertHeader('RateLimit-Limit', '2');
});
