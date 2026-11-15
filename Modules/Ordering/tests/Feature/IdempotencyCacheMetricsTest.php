<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Ordering\Services\IdempotencyCache;
use Tests\Support\MetricsTesting;
use Tests\Support\OrderingTesting;

beforeEach(function (): void {
    OrderingTesting::configure();
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
    Log::shouldReceive('channel')->with('app')->andReturnSelf();
    Log::shouldReceive('warning');
});

it('counts a failed state-store read and write as component=idempotency and still answers', function (): void {
    Cache::shouldReceive('store')->with('state')->andThrow(new RuntimeException('simulated Redis outage'));
    $cache = new IdempotencyCache;

    expect($cache->get('user', 'key'))->toBeNull();
    $cache->put('user', 'key', str_repeat('a', 64), 201, ['data' => []]);

    expect(MetricsTesting::sample('aghigh_state_store_errors_total{component="idempotency"}'))->toBe(2.0);
});

it('does not count anything while the state store works', function (): void {
    $cache = new IdempotencyCache;
    $cache->put('user', 'key', str_repeat('a', 64), 201, ['data' => ['id' => 1]]);

    expect($cache->get('user', 'key'))->not->toBeNull()
        ->and(MetricsTesting::has('aghigh_state_store_errors_total'))->toBeFalse();
});
