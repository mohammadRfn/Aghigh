<?php

declare(strict_types=1);

namespace Modules\Ordering\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\OrderStateMachine;

/**
 * Customer cancellation: state machine first (the conditional UPDATE decides against a concurrent
 * pay/expire), then release the reservations, all in one short transaction. Paid orders are
 * refused here: the refund path belongs to Payment (Task 6) even though the transition map allows it.
 */
final class CancelOrder
{
    public function __construct(private readonly OrderStateMachine $machine, private readonly StockReservations $stock) {}

    public function __invoke(Order $order): Order
    {
        if ($order->status === OrderStatus::Paid) {
            throw new OrderingProblem('Paid orders are cancelled through the refund path.', 409, 'invalid-order-transition', ['from' => 'paid', 'to' => 'cancelled']);
        }

        return DB::transaction(function () use ($order): Order {
            $this->machine->transition($order, OrderStatus::Cancelled);
            $this->stock->release($order->id);

            return $order;
        });
    }
}
