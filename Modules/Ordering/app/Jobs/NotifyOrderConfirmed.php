<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\OrderStateMachine;

/**
 * Saga step 3 (after the pivot): hand the confirmation mail to the notifications queue, then mark the
 * order notified. Push first, mark second = at-least-once (a crash in between may send twice; a
 * missing mail is worse). Its failure compensates nothing: CompensateOrder skips confirmed orders.
 */
final class NotifyOrderConfirmed extends OrderSagaJob
{
    public int $tries = 5;

    public function handle(OrderStateMachine $machine): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null || $order->confirmed_at === null || $order->notified_at !== null) {
            return;
        }

        SendOrderConfirmation::dispatch($order->id);
        $machine->markNotified($order);
    }
}
