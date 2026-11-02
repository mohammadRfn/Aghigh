<?php

declare(strict_types=1);

use Modules\Ordering\Enums\OrderStatus;

it('allows only the documented transitions', function (): void {
    $actual = [];
    foreach (OrderStatus::transitions() as $from => $targets) {
        $actual[$from] = array_map(fn (OrderStatus $status): string => $status->value, $targets);
    }

    expect($actual)->toBe([
        'pending' => ['reserved', 'cancelled'],
        'reserved' => ['paid', 'cancelled', 'expired'],
        'paid' => ['fulfilled', 'cancelled'],
        'fulfilled' => [],
        'cancelled' => [],
        'expired' => [],
    ]);
});

it('never lets an order skip, reverse or repeat a state', function (OrderStatus $from, OrderStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'pending to paid' => [OrderStatus::Pending, OrderStatus::Paid],
    'pending to expired' => [OrderStatus::Pending, OrderStatus::Expired],
    'reserved to pending' => [OrderStatus::Reserved, OrderStatus::Pending],
    'reserved to fulfilled' => [OrderStatus::Reserved, OrderStatus::Fulfilled],
    'paid to expired' => [OrderStatus::Paid, OrderStatus::Expired],
    'paid to reserved' => [OrderStatus::Paid, OrderStatus::Reserved],
    'cancelled to reserved' => [OrderStatus::Cancelled, OrderStatus::Reserved],
    'expired to reserved' => [OrderStatus::Expired, OrderStatus::Reserved],
    'fulfilled to cancelled' => [OrderStatus::Fulfilled, OrderStatus::Cancelled],
    'cancelled to cancelled' => [OrderStatus::Cancelled, OrderStatus::Cancelled],
]);

it('marks fulfilled cancelled and expired as terminal', function (): void {
    expect(OrderStatus::terminalValues())->toBe(['fulfilled', 'cancelled', 'expired'])
        ->and(OrderStatus::Reserved->isTerminal())->toBeFalse()
        ->and(OrderStatus::Paid->isTerminal())->toBeFalse();
});

it('names the outbox event written when an order enters a status', function (): void {
    expect(OrderStatus::Reserved->eventType())->toBe('OrderReserved')
        ->and(OrderStatus::Paid->eventType())->toBe('OrderPaid')
        ->and(OrderStatus::Fulfilled->eventType())->toBe('OrderFulfilled')
        ->and(OrderStatus::Cancelled->eventType())->toBe('OrderCancelled')
        ->and(OrderStatus::Expired->eventType())->toBe('OrderExpired');
});
