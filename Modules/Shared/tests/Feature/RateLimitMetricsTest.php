<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Modules\Shared\Events\RateLimitBackendFailed;
use Modules\Shared\Events\RateLimitHit;
use Modules\Shared\Metrics\Listeners\RateLimitMetrics;
use Tests\Support\MetricsTesting;
use Tests\Support\RateLimitTesting;

beforeEach(function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
});

it('is wired to both limiter events', function (): void {
    $raw = Event::getRawListeners();

    expect($raw[RateLimitHit::class] ?? [])->toContain([RateLimitMetrics::class, 'hit'])
        ->and($raw[RateLimitBackendFailed::class] ?? [])->toContain([RateLimitMetrics::class, 'backendFailed']);
});

it('counts a hit by limiter name and driver only, never by key, IP or route', function (): void {
    app(RateLimitMetrics::class)->hit(new RateLimitHit('orders-ip', 'abcd1234abcd1234', '203.0.113.9', 'api.v1.ordering.orders.store', 'sliding', 58));

    $body = MetricsTesting::body();
    expect(MetricsTesting::sample('aghigh_rate_limit_hits_total{limiter="orders_ip",driver="sliding"}'))->toBe(1.0)
        ->and($body)->not->toContain('203.0.113.9')->and($body)->not->toContain('abcd1234')->and($body)->not->toContain('orders.store');
});

it('counts a backend failure as a limiter state-store error', function (): void {
    app(RateLimitMetrics::class)->backendFailed(new RateLimitBackendFailed('orders-ip', 'closed', RuntimeException::class));

    expect(MetricsTesting::sample('aghigh_state_store_errors_total{component="limiter"}'))->toBe(1.0);
});

it('counts a real 429 end to end through the throttle middleware', function (): void {
    RateLimitTesting::exact(['public-catalog' => 1]);
    Route::middleware(['api', 'throttle.sliding:public-catalog'])->get('/api/v1/_t/obs-limit', fn () => response()->json(['ok' => true]))->name('t.obs.limit');

    $this->getJson('/api/v1/_t/obs-limit')->assertOk();
    expect(MetricsTesting::has('aghigh_rate_limit_hits_total'))->toBeFalse();

    $this->getJson('/api/v1/_t/obs-limit')->assertStatus(429);
    expect(MetricsTesting::sample('aghigh_rate_limit_hits_total{limiter="public_catalog",driver="laravel"}'))->toBe(1.0);
});
