<?php

declare(strict_types=1);

namespace Modules\Ordering\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Models\Order;
use Modules\Shared\Outbox\Outbox;

/**
 * The single place that changes an order's status. Validates against OrderStatus::transitions(),
 * then applies `UPDATE orders SET status = ?, version = version + 1 WHERE id = ? AND status = ?`:
 * rows affected decides the winner of concurrent transitions (cancel vs pay, cancel vs expire).
 * The outbox event is written in the same transaction. Anything else is 409 "invalid-order-transition".
 */
final class OrderStateMachine
{
    public function __construct(private readonly Outbox $outbox) {}

    public function transition(Order $order, OrderStatus $to): Order
    {
        $from = $order->status;
        if (! $from->canTransitionTo($to)) {
            throw new OrderingProblem(
                sprintf('An order cannot move from %s to %s.', $from->value, $to->value),
                409,
                'invalid-order-transition',
                ['from' => $from->value, 'to' => $to->value],
            );
        }

        return DB::transaction(function () use ($order, $from, $to): Order {
            $now = CarbonImmutable::now();
            $affected = DB::table('orders')
                ->where('id', $order->id)
                ->where('status', $from->value)
                ->update(['status' => $to->value, 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
            if ($affected !== 1) {
                throw new OrderingProblem(
                    'The order changed concurrently; reload it and retry.',
                    409,
                    'invalid-order-transition',
                    ['from' => $from->value, 'to' => $to->value],
                );
            }
            $this->outbox->record('order', $order->id, $to->eventType(), [
                'order_id' => $order->id,
                'occurred_at' => $now->toIso8601ZuluString('microsecond'),
            ]);
            $order->forceFill(['status' => $to, 'version' => $order->version + 1, 'updated_at' => $now])->syncOriginal();

            return $order;
        });
    }
}
