<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\OrderStateMachine;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

function orderIn(OrderStatus $status): Order
{
    return Order::query()->create([
        'id' => (string) Str::uuid7(), 'user_id' => (string) Str::uuid7(), 'status' => $status, 'total_minor' => 1000,
        'currency' => config('catalog.currency'), 'version' => 1, 'expires_at' => now()->addMinutes(15),
    ]);
}

it('walks the happy path and bumps the version on every transition', function (): void {
    $machine = app(OrderStateMachine::class);
    $order = orderIn(OrderStatus::Pending);

    foreach ([OrderStatus::Reserved, OrderStatus::Paid, OrderStatus::Fulfilled] as $index => $to) {
        $machine->transition($order, $to);
        expect($order->status)->toBe($to)
            ->and($order->version)->toBe($index + 2)
            ->and(DB::table('orders')->where('id', $order->id)->value('status'))->toBe($to->value)
            ->and((int) DB::table('orders')->where('id', $order->id)->value('version'))->toBe($index + 2);
    }
});

it('refuses every transition outside the map and leaves the order untouched', function (): void {
    $machine = app(OrderStateMachine::class);

    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            if ($from->canTransitionTo($to)) {
                continue;
            }
            $order = orderIn($from);

            $error = OrderingTesting::thrown(fn () => $machine->transition($order, $to));

            expect($error)->toBeInstanceOf(OrderingProblem::class)
                ->and($error->problemStatus())->toBe(409)
                ->and($error->problemType())->toBe('invalid-order-transition')
                ->and($error->problemExtensions())->toBe(['from' => $from->value, 'to' => $to->value])
                ->and(DB::table('orders')->where('id', $order->id)->value('status'))->toBe($from->value)
                ->and((int) DB::table('orders')->where('id', $order->id)->value('version'))->toBe(1);
        }
    }
});

it('lets the conditional update decide when the order changed behind its back', function (): void {
    $order = orderIn(OrderStatus::Reserved);
    DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);

    $error = OrderingTesting::thrown(fn () => app(OrderStateMachine::class)->transition($order, OrderStatus::Paid));

    expect($error)->toBeInstanceOf(OrderingProblem::class)
        ->and($error->problemType())->toBe('invalid-order-transition')
        ->and(DB::table('orders')->where('id', $order->id)->value('status'))->toBe('cancelled');
});
