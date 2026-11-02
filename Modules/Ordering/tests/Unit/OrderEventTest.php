<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Events\OrderReserved;
use Modules\Shared\Events\OutboxMessage;

it('rebuilds an event from an outbox message', function (): void {
    $message = new OutboxMessage(
        'evt-1', 'order', 'o1', 'OrderReserved',
        ['order_id' => 'o1', 'occurred_at' => '2026-01-02T03:04:05.123456Z'],
        CarbonImmutable::parse('2026-01-01T00:00:00Z'), 3,
    );

    $event = OrderReserved::fromOutbox($message);

    expect($event)->toBeInstanceOf(OrderReserved::class)
        ->and($event->orderId)->toBe('o1')
        ->and($event->eventId)->toBe('evt-1')
        ->and($event->attempt)->toBe(3)
        ->and($event->occurredAt->toIso8601ZuluString('microsecond'))->toBe('2026-01-02T03:04:05.123456Z');
});

it('falls back to the aggregate id and the row time for a sparse payload', function (): void {
    $at = CarbonImmutable::parse('2026-01-01T00:00:00Z');

    $event = OrderReserved::fromOutbox(new OutboxMessage('evt-2', 'order', 'o9', 'OrderReserved', [], $at));

    expect($event->orderId)->toBe('o9')->and($event->occurredAt->equalTo($at))->toBeTrue()->and($event->attempt)->toBe(1);
});

it('has an event class for every status an order can enter', function (): void {
    foreach (OrderStatus::cases() as $status) {
        if ($status === OrderStatus::Pending) {
            continue;
        }
        expect(class_exists('Modules\\Ordering\\Events\\'.$status->eventType()))->toBeTrue();
    }
});
