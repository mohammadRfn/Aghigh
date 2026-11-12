<?php

declare(strict_types=1);

namespace Modules\Ordering\Listeners;

use Modules\Ordering\Events\OrderCancelled;
use Modules\Ordering\Events\OrderConfirmed;
use Modules\Ordering\Events\OrderEvent;
use Modules\Ordering\Events\OrderExpired;
use Modules\Ordering\Events\OrderFulfilled;
use Modules\Ordering\Events\OrderPaid;
use Modules\Ordering\Events\OrderReserved;
use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Metrics\Names;

/**
 * Task 9: order outcomes -> aghigh_orders_total{outcome}. Lives in Ordering (the owning module) and uses only the
 * Shared Metrics contract. The events arrive through the outbox relay (at-least-once), so the counter can
 * over-count on a redelivery; it is a trend, the orders table is the truth.
 */
final class RecordOrderMetrics
{
    public function __construct(private readonly Metrics $metrics) {}

    public function handle(OrderEvent $event): void
    {
        $outcome = match (true) {
            $event instanceof OrderReserved => 'reserved',
            $event instanceof OrderPaid => 'paid',
            $event instanceof OrderConfirmed => 'confirmed',
            $event instanceof OrderFulfilled => 'fulfilled',
            $event instanceof OrderCancelled => 'cancelled',
            $event instanceof OrderExpired => 'expired',
            default => 'other',
        };

        $this->metrics->counter(Names::ORDERS, ['outcome' => $outcome]);
    }
}
