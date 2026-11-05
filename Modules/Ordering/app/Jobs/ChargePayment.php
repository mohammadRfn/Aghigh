<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Exceptions\SagaStepRejected;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\OrderStateMachine;
use Modules\Payment\Contracts\GatewayUnavailable;
use Modules\Payment\Contracts\PaymentCharger;
use Modules\Shared\Queue\Middleware\FailWithoutRetry;
use Modules\Shared\Queue\Middleware\ThrottlesExceptionsWithBackoff;
use Throwable;

/**
 * Saga step 1: charge, then reserved -> paid. Idempotent: an already paid order returns at once; the
 * payment row is unique per order and the gateway key is the order id. A transient gateway error is
 * released with the exponential backoff (ThrottlesExceptions opens a shared circuit when the gateway
 * keeps failing); a decline or an order that is no longer payable fails the step for compensation.
 */
final class ChargePayment extends OrderSagaJob
{
    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function middleware(): array
    {
        return [
            new FailWithoutRetry([SagaStepRejected::class]),
            self::orderLock($this->orderId, $this->timeout),
            self::gatewayThrottle(),
        ];
    }

    public static function gatewayThrottle(): ThrottlesExceptionsWithBackoff
    {
        return (new ThrottlesExceptionsWithBackoff(
            (int) config('ordering.saga.gateway_max_exceptions', 20),
            (int) config('ordering.saga.gateway_decay_seconds', 60),
        ))->by('ordering:payment-gateway')->when(fn (Throwable $error): bool => $error instanceof GatewayUnavailable);
    }

    public function handle(PaymentCharger $payments, OrderStateMachine $machine): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null || in_array($order->status, [OrderStatus::Paid, OrderStatus::Fulfilled], true)) {
            return; // unknown order, or a duplicate delivery after the charge: nothing to do
        }
        if ($order->status !== OrderStatus::Reserved) {
            throw new SagaStepRejected("Order is {$order->status->value}; it is not charged.");
        }

        $outcome = $payments->charge($order->id, $order->total_minor, $order->currency);
        if (! $outcome->succeeded()) {
            throw new SagaStepRejected('Payment '.($outcome->status->value ?? 'missing').($outcome->failureCode !== null ? ' ('.$outcome->failureCode.')' : '').'.');
        }

        try {
            $machine->transition($order, OrderStatus::Paid);
        } catch (OrderingProblem $problem) {
            $fresh = $order->fresh();
            if ($fresh !== null && in_array($fresh->status, [OrderStatus::Paid, OrderStatus::Fulfilled], true)) {
                return; // a duplicate delivery won the transition
            }
            // Expired or cancelled while the gateway answered: compensation refunds the charge.
            throw new SagaStepRejected('Charged, but the order changed concurrently ('.$problem->getMessage().')', 0, $problem);
        }
    }
}
