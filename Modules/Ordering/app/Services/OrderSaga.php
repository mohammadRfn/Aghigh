<?php

declare(strict_types=1);

namespace Modules\Ordering\Services;

use Illuminate\Support\Facades\Bus;
use Modules\Ordering\Jobs\ChargePayment;
use Modules\Ordering\Jobs\ConfirmOrder;
use Modules\Ordering\Jobs\NotifyOrderConfirmed;
use Modules\Ordering\Saga\CompensateOnChainFailure;
use Modules\Shared\Queue\Queues;

/**
 * Orchestrated saga (ADR 0007): ChargePayment -> ConfirmOrder (pivot) -> NotifyOrderConfirmed on the
 * orders queue. Every job is afterCommit: called inside the POST /orders transaction, nothing is pushed
 * unless that transaction commits. A failure before the pivot compensates (cancel + release stock,
 * refund if charged); after the pivot it never does. If the push itself is lost, the reservation expiry
 * sweep releases the stock.
 */
final class OrderSaga
{
    public function start(string $orderId): void
    {
        if (! (bool) config('ordering.saga.enabled', true)) {
            return;
        }

        Bus::chain([new ChargePayment($orderId), new ConfirmOrder($orderId), new NotifyOrderConfirmed($orderId)])
            ->onQueue(Queues::ORDERS)
            ->catch(new CompensateOnChainFailure($orderId))
            ->dispatch();
    }
}
