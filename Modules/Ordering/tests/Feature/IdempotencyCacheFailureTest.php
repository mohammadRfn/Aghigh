<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Ordering\Services\IdempotencyCache;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

it('treats a failing state store as a cache miss and ignores failed writes', function (): void {
    Cache::shouldReceive('store')->with('state')->andThrow(new RuntimeException('simulated Redis outage'));
    Log::shouldReceive('channel')->with('app')->andReturnSelf();
    Log::shouldReceive('warning')->twice();
    $cache = new IdempotencyCache;

    expect($cache->get('user', 'key'))->toBeNull()
        ->and(fn () => $cache->put('user', 'key', str_repeat('a', 64), 201, ['data' => []]))->not->toThrow(Throwable::class);
});

it('still places and replays orders from the database while the state store is down', function (): void {
    $product = OrderingTesting::product(10);
    $user = (string) Str::uuid7();
    $key = OrderingTesting::key();
    $items = [['product_id' => $product, 'quantity' => 2]];
    Cache::shouldReceive('store')->with('state')->andThrow(new RuntimeException('simulated Redis outage'));

    $first = OrderingTesting::place($user, $key, $items);
    $second = OrderingTesting::place($user, $key, $items);

    expect($first->status)->toBe(201)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeTrue()
        ->and($second->orderId)->toBe($first->orderId)
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(2);
});
