<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\SagaStepRejected;
use Modules\Ordering\Models\Order;
use Modules\Payment\Contracts\PaymentCharger;
use Modules\Shared\Queue\Middleware\FailWithoutRetry;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Throwable;

/**
 * Compensation step: refund only orders that are already cancelled or expired. Idempotent in Payment.
 * Its overlap lock is keyed by order id but NOT shared with the saga steps: the order is terminal by
 * now, so no step can race it, and CompensateOrder (which dispatches it) may still hold the order lock.
 */
final class RefundPayment extends OrderSagaJob implements ShouldBeUnique
{
    public int $tries = 10;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(WithoutOverlappingOnState::store());
    }

    public function middleware(): array
    {
        return [
            new FailWithoutRetry([SagaStepRejected::class]),
            (new WithoutOverlappingOnState('ordering:refund:'.$this->orderId))->shared()->releaseAfter(5)->expireAfter($this->timeout + 15),
            ChargePayment::gatewayThrottle(),
        ];
    }

    public function handle(PaymentCharger $payments): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order !== null && ! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Expired], true)) {
            throw new SagaStepRejected("Order is {$order->status->value}; refunds follow a cancellation or an expiry.");
        }
        $payments->refund($this->orderId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('audit')->critical('order_refund_failed', ['order_id' => $this->orderId, 'exception' => $exception === null ? null : $exception::class]);
    }
}
