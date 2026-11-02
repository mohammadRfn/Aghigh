<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Ordering\Services\IdempotencyCache;
use Tests\Support\OrderingTesting;

beforeEach(function (): void {
    OrderingTesting::configure();
});

it('round-trips a completed response', function (): void {
    $cache = new IdempotencyCache;

    $cache->put('user-1', 'key-1', str_repeat('a', 64), 201, ['data' => ['id' => 'o1']]);

    expect($cache->get('user-1', 'key-1'))->toBe(['request_hash' => str_repeat('a', 64), 'status' => 201, 'body' => ['data' => ['id' => 'o1']]]);
});

it('is scoped per user and key', function (): void {
    $cache = new IdempotencyCache;
    $cache->put('user-1', 'key-1', str_repeat('a', 64), 201, ['data' => []]);

    expect($cache->get('user-2', 'key-1'))->toBeNull()
        ->and($cache->get('user-1', 'key-2'))->toBeNull();
});

it('ignores malformed entries', function (): void {
    Cache::store('state')->put('ordering:idempotency:user-1:key-1', ['unexpected' => true], 60);
    Cache::store('state')->put('ordering:idempotency:user-1:key-2', 'not-an-array', 60);

    $cache = new IdempotencyCache;

    expect($cache->get('user-1', 'key-1'))->toBeNull()
        ->and($cache->get('user-1', 'key-2'))->toBeNull();
});
