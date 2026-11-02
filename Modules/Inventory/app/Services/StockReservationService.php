<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Inventory\Contracts\StockStrategy;
use Modules\Inventory\Enums\ReservationStatus;

/**
 * Strategy-independent half of reservations: ordering of products, reservation rows and the
 * reverse moves (release / commit / expire). Every reverse move is a conditional
 * `UPDATE ... WHERE status = 'active'` whose rows-affected count gates the counter update, so each
 * reservation row is settled exactly once however many workers race on it.
 * Stock rows are always touched in ascending product_id order (same as the strategies): no cycles.
 */
final class StockReservationService implements StockReservations
{
    public function __construct(private readonly StockStrategy $strategy) {}

    public function reserve(string $orderId, array $quantities, DateTimeInterface $expiresAt): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('StockReservations::reserve() must run inside the order transaction.');
        }
        $sorted = self::sorted($quantities);
        $this->strategy->reserve($sorted);

        $now = now();
        $rows = [];
        foreach ($sorted as $productId => $quantity) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'order_id' => $orderId, 'product_id' => $productId, 'quantity' => $quantity,
                'status' => ReservationStatus::Active->value, 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('stock_reservations')->insert($rows);
    }

    public function release(string $orderId): int
    {
        return $this->settle($orderId, ReservationStatus::Released);
    }

    public function commit(string $orderId): int
    {
        return $this->settle($orderId, ReservationStatus::Committed);
    }

    public function releaseExpired(int $limit): array
    {
        $limit = max(1, min($limit, 10_000));

        return DB::transaction(function () use ($limit): array {
            $rows = DB::table('stock_reservations')
                ->where('status', ReservationStatus::Active->value)
                ->where('expires_at', '<=', now())
                ->orderBy('expires_at')->orderBy('id')
                ->limit($limit)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)
                ->get(['id', 'order_id', 'product_id', 'quantity']);

            $orders = [];
            foreach ($rows->sortBy('product_id') as $row) {
                if ($this->settleRow($row, ReservationStatus::Expired) === 1) {
                    $orders[(string) $row->order_id] = true;
                }
            }

            return array_keys($orders);
        });
    }

    private function settle(string $orderId, ReservationStatus $to): int
    {
        return DB::transaction(function () use ($orderId, $to): int {
            $rows = DB::table('stock_reservations')
                ->where('order_id', $orderId)->where('status', ReservationStatus::Active->value)
                ->orderBy('product_id')->lockForUpdate()->get(['id', 'order_id', 'product_id', 'quantity']);

            $settled = 0;
            foreach ($rows as $row) {
                $settled += $this->settleRow($row, $to);
            }

            return $settled;
        });
    }

    /** Flip one active reservation and move its quantity; returns 1 when this call did the work. */
    private function settleRow(object $row, ReservationStatus $to): int
    {
        $flipped = DB::table('stock_reservations')->where('id', $row->id)
            ->where('status', ReservationStatus::Active->value)
            ->update(['status' => $to->value, 'updated_at' => now()]);
        if ($flipped !== 1) {
            return 0; // someone else settled it first; idempotent
        }
        $quantity = (int) $row->quantity;
        $delta = match ($to) {
            ReservationStatus::Committed => ['reserved' => DB::raw('reserved - '.$quantity), 'sold' => DB::raw('sold + '.$quantity)],
            default => ['available' => DB::raw('available + '.$quantity), 'reserved' => DB::raw('reserved - '.$quantity)],
        };
        $updated = DB::table('stock_items')->where('product_id', $row->product_id)->update($delta + ['updated_at' => now()]);
        if ($updated !== 1) {
            throw new LogicException('Stock row missing for reservation '.$row->id.'; refusing to continue.');
        }

        return 1;
    }

    /**
     * @param  array<string, int>  $quantities
     * @return array<string, int>
     */
    private static function sorted(array $quantities): array
    {
        if ($quantities === []) {
            throw new LogicException('A reservation needs at least one product.');
        }
        $sorted = [];
        foreach ($quantities as $productId => $quantity) {
            $productId = strtolower((string) $productId);
            if (! Str::isUuid($productId) || $quantity < 1) {
                throw new LogicException('Reservations need UUID product ids and quantities >= 1.');
            }
            $sorted[$productId] = ($sorted[$productId] ?? 0) + $quantity;
        }
        ksort($sorted, SORT_STRING); // ascending product_id: identical lock order in every strategy

        return $sorted;
    }
}
