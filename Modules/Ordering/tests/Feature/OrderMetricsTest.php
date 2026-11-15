<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Ordering\Events\OrderCancelled;
use Modules\Ordering\Events\OrderConfirmed;
use Modules\Ordering\Events\OrderEvent;
use Modules\Ordering\Events\OrderExpired;
use Modules\Ordering\Events\OrderFulfilled;
use Modules\Ordering\Events\OrderPaid;
use Modules\Ordering\Events\OrderReserved;
use Modules\Ordering\Listeners\RecordOrderMetrics;
use Tests\Support\MetricsTesting;

beforeEach(function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
});

it('is wired to every order lifecycle event', function (): void {
    $raw = Event::getRawListeners();

    foreach ([OrderReserved::class, OrderPaid::class, OrderConfirmed::class, OrderFulfilled::class, OrderCancelled::class, OrderExpired::class] as $event) {
        expect($raw[$event] ?? [])->toContain(RecordOrderMetrics::class);
    }
});

it('maps each event to its outcome label', function (): void {
    $listener = app(RecordOrderMetrics::class);
    $now = CarbonImmutable::now();

    foreach (['reserved' => OrderReserved::class, 'paid' => OrderPaid::class, 'confirmed' => OrderConfirmed::class, 'fulfilled' => OrderFulfilled::class, 'cancelled' => OrderCancelled::class, 'expired' => OrderExpired::class] as $outcome => $class) {
        $listener->handle(new $class('order-1', $now, 'event-'.$outcome));
        expect(MetricsTesting::sample('aghigh_orders_total{outcome="'.$outcome.'"}'))->toBe(1.0);
    }

    expect(MetricsTesting::body())->not->toContain('order-1')->and(MetricsTesting::body())->not->toContain('event-');
});

it('counts every delivery, because the outbox is at-least-once', function (): void {
    $listener = app(RecordOrderMetrics::class);
    $event = new OrderPaid('order-1', CarbonImmutable::now(), 'event-1');

    $listener->handle($event);
    $listener->handle($event);

    expect(MetricsTesting::sample('aghigh_orders_total{outcome="paid"}'))->toBe(2.0);
});

it('labels an event it does not know as other instead of dropping it', function (): void {
    $unknown = new readonly class('order-1', CarbonImmutable::now(), 'event-1') extends OrderEvent {};

    app(RecordOrderMetrics::class)->handle($unknown);

    expect(MetricsTesting::sample('aghigh_orders_total{outcome="other"}'))->toBe(1.0);
});
