<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\SagaStepRejected;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\OrderStateMachine;

/**
 * Saga step 2, the PIVOT: one transaction sets confirmed_at (conditional on paid + not confirmed),
 * records OrderConfirmed in the outbox and commits the reservations (reserved -> sold). The
 * conditional UPDATE makes duplicates (sequential or parallel) commit stock exactly once.
 */
final class ConfirmOrder extends OrderSagaJob
{
    public int $tries = 5;

    public function handle(OrderStateMachine $machine, StockReservations $stock): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null || $order->confirmed_at !== null) {
            return; // duplicate delivery: already confirmed
        }
        if ($order->status !== OrderStatus::Paid) {
            throw new SagaStepRejected("Order is {$order->status->value}; only paid orders are confirmed.");
        }

        DB::transaction(function () use ($order, $machine, $stock): void {
            if (! $machine->markConfirmed($order)) {
                return; // a concurrent duplicate confirmed first
            }
            if ($stock->commit($order->id) < 1) {
                throw new SagaStepRejected('No active reservation left to commit.'); // rolls the confirmation back
            }
        });
    }
}
