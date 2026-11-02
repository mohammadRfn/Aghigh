<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ordering\Models\Order;
use Modules\Shared\Contracts\ProblemException;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

it('places a reserved order priced on the server and reserves stock', function (): void {
    $a = OrderingTesting::product(10, ['price_minor' => 1000]);
    $b = OrderingTesting::product(5, ['price_minor' => 250]);
    $user = (string) Str::uuid7();
    $key = OrderingTesting::key();

    $placement = OrderingTesting::place($user, $key, [['product_id' => $a, 'quantity' => 3], ['product_id' => $b, 'quantity' => 2]]);

    $order = Order::query()->findOrFail($placement->orderId);
    expect($placement->status)->toBe(201)
        ->and($placement->replayed)->toBeFalse()
        ->and($placement->body['data']['status'])->toBe('reserved')
        ->and($placement->body['data']['total_minor'])->toBe(3500)
        ->and($placement->body['data']['items'])->toHaveCount(2)
        ->and($order->user_id)->toBe($user)
        ->and($order->status->value)->toBe('reserved')
        ->and($order->version)->toBe(2)
        ->and($order->total_minor)->toBe(3500)
        ->and($order->expires_at->getTimestamp())->toBeGreaterThan(now()->addSeconds((int) config('ordering.reservation_ttl_seconds') - 30)->getTimestamp())
        ->and(OrderingTesting::counters($a))->toBe(['initial' => 10, 'available' => 7, 'reserved' => 3, 'sold' => 0])
        ->and(OrderingTesting::counters($b))->toBe(['initial' => 5, 'available' => 3, 'reserved' => 2, 'sold' => 0])
        ->and(DB::table('stock_reservations')->where('order_id', $order->id)->where('status', 'active')->count())->toBe(2)
        ->and(DB::table('idempotency_keys')->where('user_id', $user)->where('key', $key)->value('status'))->toBe('completed')
        ->and((int) DB::table('idempotency_keys')->where('key', $key)->value('response_status'))->toBe(201)
        ->and(DB::table('idempotency_keys')->where('key', $key)->value('order_id'))->toBe($order->id);
});

it('replays the stored response for the same key and payload', function (string $path): void {
    $product = OrderingTesting::product(10, ['price_minor' => 1000]);
    $user = (string) Str::uuid7();
    $key = OrderingTesting::key();
    $items = [['product_id' => $product, 'quantity' => 2]];
    $first = OrderingTesting::place($user, $key, $items);
    if ($path === 'database') {
        Cache::store('state')->flush();
    }

    $second = OrderingTesting::place($user, $key, $items);

    expect($second->replayed)->toBeTrue()
        ->and($second->status)->toBe(201)
        ->and($second->orderId)->toBe($first->orderId)
        ->and($second->body)->toEqual($first->body)
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(2);
})->with(['cache', 'database']);

it('refuses the same key with a different payload', function (string $path): void {
    $product = OrderingTesting::product(10);
    $user = (string) Str::uuid7();
    $key = OrderingTesting::key();
    OrderingTesting::place($user, $key, [['product_id' => $product, 'quantity' => 1]]);
    if ($path === 'database') {
        Cache::store('state')->flush();
    }

    $error = OrderingTesting::thrown(fn () => OrderingTesting::place($user, $key, [['product_id' => $product, 'quantity' => 2]]));

    expect($error)->toBeInstanceOf(ProblemException::class)
        ->and($error->problemStatus())->toBe(422)
        ->and($error->problemType())->toBe('idempotency-key-reuse')
        ->and(DB::table('orders')->count())->toBe(1);
})->with(['cache', 'database']);

it('scopes idempotency keys per user', function (): void {
    $product = OrderingTesting::product(10);
    $key = OrderingTesting::key();
    $items = [['product_id' => $product, 'quantity' => 1]];

    $one = OrderingTesting::place((string) Str::uuid7(), $key, $items);
    $two = OrderingTesting::place((string) Str::uuid7(), $key, $items);

    expect($two->replayed)->toBeFalse()
        ->and($two->orderId)->not->toBe($one->orderId)
        ->and(DB::table('orders')->count())->toBe(2)
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(2);
});

it('rolls everything back when stock is insufficient and allows a retry with the same key', function (): void {
    $product = OrderingTesting::product(2);
    $user = (string) Str::uuid7();
    $key = OrderingTesting::key();
    $items = [['product_id' => $product, 'quantity' => 3]];

    $error = OrderingTesting::thrown(fn () => OrderingTesting::place($user, $key, $items));

    expect($error)->toBeInstanceOf(ProblemException::class)
        ->and($error->problemStatus())->toBe(409)
        ->and($error->problemType())->toBe('out-of-stock')
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0)
        ->and(DB::table('idempotency_keys')->count())->toBe(0)
        ->and(DB::table('stock_reservations')->count())->toBe(0)
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 2, 'available' => 2, 'reserved' => 0, 'sold' => 0]);

    DB::table('stock_items')->where('product_id', $product)->update(['initial' => 5, 'available' => 5]);
    $retry = OrderingTesting::place($user, $key, $items);

    expect($retry->status)->toBe(201)->and($retry->replayed)->toBeFalse();
});

it('rejects unknown and inactive products without storing anything', function (): void {
    $draft = OrderingTesting::product(5, ['status' => 'draft']);
    $items = [['product_id' => (string) Str::uuid7(), 'quantity' => 1], ['product_id' => $draft, 'quantity' => 1]];

    $error = OrderingTesting::thrown(fn () => OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), $items));

    expect($error)->toBeInstanceOf(ValidationException::class)
        ->and($error->errors())->toHaveKeys(['items.0.product_id', 'items.1.product_id'])
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('idempotency_keys')->count())->toBe(0);
});

it('rejects a line or an order total above the configured maximum', function (): void {
    config(['ordering.max_total_minor' => 1000]);
    $expensive = OrderingTesting::product(10, ['price_minor' => 600]);
    $cheap = [OrderingTesting::product(10, ['price_minor' => 400]), OrderingTesting::product(10, ['price_minor' => 400]), OrderingTesting::product(10, ['price_minor' => 400])];

    $line = OrderingTesting::thrown(fn () => OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => $expensive, 'quantity' => 2]]));
    $total = OrderingTesting::thrown(fn () => OrderingTesting::place(
        (string) Str::uuid7(), OrderingTesting::key(), array_map(fn (string $id): array => ['product_id' => $id, 'quantity' => 1], $cheap),
    ));

    expect($line)->toBeInstanceOf(ValidationException::class)->and($line->errors())->toHaveKey('items.0.quantity')
        ->and($total)->toBeInstanceOf(ValidationException::class)->and($total->errors())->toHaveKey('items')
        ->and(DB::table('orders')->count())->toBe(0);
});

it('accepts upper case product ids and stores them in lower case', function (): void {
    $product = OrderingTesting::product(5);

    $placement = OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => strtoupper($product), 'quantity' => 2]]);

    expect($placement->status)->toBe(201)
        ->and(DB::table('order_items')->value('product_id'))->toBe($product)
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(2);
});
