<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ordering\Actions\CancelOrder;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Models\Order;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

function reservedOrder(string $product): Order
{
    $placement = OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => $product, 'quantity' => 4]]);

    return Order::query()->findOrFail($placement->orderId);
}

it('cancels a reserved order and releases its stock', function (): void {
    $product = OrderingTesting::product(10);
    $order = reservedOrder($product);

    $cancelled = app(CancelOrder::class)($order);

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->version)->toBe(3)
        ->and(DB::table('orders')->where('id', $order->id)->value('status'))->toBe('cancelled')
        ->and(DB::table('stock_reservations')->where('order_id', $order->id)->value('status'))->toBe('released')
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0]);
});

it('refuses to cancel an order twice', function (): void {
    $order = reservedOrder(OrderingTesting::product(10));
    app(CancelOrder::class)($order);

    $error = OrderingTesting::thrown(fn () => app(CancelOrder::class)(Order::query()->findOrFail($order->id)));

    expect($error)->toBeInstanceOf(OrderingProblem::class)
        ->and($error->problemStatus())->toBe(409)
        ->and($error->problemType())->toBe('invalid-order-transition')
        ->and($error->problemExtensions())->toBe(['from' => 'cancelled', 'to' => 'cancelled']);
});

it('sends paid orders to the refund path and keeps their stock reserved', function (): void {
    $product = OrderingTesting::product(10);
    $order = reservedOrder($product);
    DB::table('orders')->where('id', $order->id)->update(['status' => 'paid']);

    $error = OrderingTesting::thrown(fn () => app(CancelOrder::class)(Order::query()->findOrFail($order->id)));

    expect($error)->toBeInstanceOf(OrderingProblem::class)
        ->and($error->problemStatus())->toBe(409)
        ->and($error->problemExtensions())->toBe(['from' => 'paid', 'to' => 'cancelled'])
        ->and(DB::table('stock_reservations')->where('order_id', $order->id)->value('status'))->toBe('active')
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(4);
});

it('does not release stock when the order changed concurrently', function (): void {
    $product = OrderingTesting::product(10);
    $stale = reservedOrder($product);
    DB::table('orders')->where('id', $stale->id)->update(['status' => 'expired']);

    $error = OrderingTesting::thrown(fn () => app(CancelOrder::class)($stale));

    expect($error)->toBeInstanceOf(OrderingProblem::class)
        ->and($error->problemType())->toBe('invalid-order-transition')
        ->and(DB::table('orders')->where('id', $stale->id)->value('status'))->toBe('expired')
        ->and(DB::table('stock_reservations')->where('order_id', $stale->id)->value('status'))->toBe('active')
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(4);
});
