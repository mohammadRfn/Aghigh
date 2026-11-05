<?php

declare(strict_types=1);

namespace Modules\Ordering\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Models\Order;

/**
 * Reservation expiry, ORDER first: reserved -> expired (conditional UPDATE + outbox OrderExpired) and
 * StockReservations::expire() in one short transaction per order, rows taken FOR UPDATE SKIP LOCKED.
 * A concurrent ChargePayment either wins the order row (paid, the sweep skips it) or loses it (the
 * charge is refunded by compensation). Inventory's reservation-first releaseExpired() is NOT used:
 * it cannot see that an order was just paid and would release stock a paid order still needs.
 * One order per transaction keeps lock order identical to every other writer (order row, then stock
 * rows by ascending product_id): no deadlock with POST /orders or the saga. Idempotent.
 */
final class ReservationExpiry
{
    public function __construct(private readonly OrderStateMachine $machine, private readonly StockReservations $stock) {}

    /** Returns how many orders this call expired. */
    public function sweep(int $batchSize, int $maxBatches): int
    {
        $batchSize = max(1, min($batchSize, 5000));
        $total = 0;
        for ($batch = 0; $batch < max(1, $maxBatches); $batch++) {
            $expired = $this->expireBatch($batchSize);
            $total += $expired;
            if ($expired < $batchSize) {
                break;
            }
        }

        return $total;
    }

    public function expireBatch(int $limit): int
    {
        $ids = Order::query()
            ->where('status', OrderStatus::Reserved->value)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')->orderBy('id')
            ->limit($limit)
            ->pluck('id')->all();

        $expired = 0;
        foreach ($ids as $id) {
            $expired += $this->expireOne((string) $id);
        }

        return $expired;
    }

    public function expireOne(string $orderId): int
    {
        return DB::transaction(function () use ($orderId): int {
            $order = Order::query()->whereKey($orderId)
                ->where('status', OrderStatus::Reserved->value)
                ->where('expires_at', '<=', now())
                ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)
                ->first();
            if ($order === null) {
                return 0; // paid, cancelled, already expired, or held by a concurrent transition right now
            }
            $this->machine->transition($order, OrderStatus::Expired);
            $this->stock->expire($order->id);

            return 1;
        });
    }
}
