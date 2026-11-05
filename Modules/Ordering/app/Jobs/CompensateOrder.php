<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\OrderStateMachine;
use Modules\Payment\Contracts\PaymentLedger;
use Modules\Payment\Contracts\PaymentStatus;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Throwable;

/**
 * Compensation = CancelOrder + ReleaseStock in ONE transaction (the order's conditional UPDATE first,
 * so a concurrent payment or expiry cannot interleave), then RefundPayment if money was taken or may
 * have been (pending = ambiguous timeout). Idempotent and state-driven: skips confirmed orders (past
 * the pivot), heals a closed order that still holds stock, and can run any number of times.
 * Triggered twice by design (chain catch + failed()); ShouldBeUnique collapses the duplicates.
 */
final class CompensateOrder extends OrderSagaJob implements ShouldBeUnique
{
    public int $tries = 10;

    public int $maxExceptions = 5;

    public int $uniqueFor = 300;

    public function __construct(string $orderId, public readonly string $reason = 'saga-failed')
    {
        parent::__construct($orderId);
    }

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(WithoutOverlappingOnState::store());
    }

    public function handle(OrderStateMachine $machine, StockReservations $stock, PaymentLedger $payments): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null) {
            return;
        }
        if ($order->confirmed_at !== null || $order->status === OrderStatus::Fulfilled) {
            Log::info('order_compensation_skipped', ['order_id' => $this->orderId, 'reason' => $this->reason, 'why' => 'past-pivot']);

            return;
        }

        DB::transaction(function () use ($machine, $stock): void {
            $locked = Order::query()->whereKey($this->orderId)->lockForUpdate()->first();
            if ($locked === null || $locked->confirmed_at !== null) {
                return;
            }
            if (in_array($locked->status, [OrderStatus::Pending, OrderStatus::Reserved, OrderStatus::Paid], true)) {
                $machine->transition($locked, OrderStatus::Cancelled); // paid -> cancelled is the refund path
            }
            $stock->release($locked->id); // idempotent: 0 rows when the expiry sweep already gave the stock back
        });

        $payment = $payments->status($this->orderId);
        if ($payment === PaymentStatus::Succeeded || $payment === PaymentStatus::Pending) {
            RefundPayment::dispatch($this->orderId);
        }
        Log::info('order_compensated', ['order_id' => $this->orderId, 'reason' => $this->reason, 'payment' => $payment?->value]);
    }

    public function failed(?Throwable $exception): void
    {
        // Compensation of the compensation is a human: orders:verify-invariants lists the order.
        Log::channel('audit')->critical('order_compensation_failed', ['order_id' => $this->orderId, 'reason' => $this->reason, 'exception' => $exception === null ? null : $exception::class]);
    }
}
