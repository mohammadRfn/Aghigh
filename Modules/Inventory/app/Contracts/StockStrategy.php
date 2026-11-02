<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/**
 * How stock_items counters move from available to reserved. Selected by STOCK_STRATEGY
 * (naive | atomic | locking, see ADR 0005). Extension point: a Redis/Lua flash-sale strategy
 * would implement this same contract (reserve in Redis, write-behind to Postgres) and be
 * selected by the same config key; nothing in Ordering changes.
 *
 * Implementations run inside the caller's transaction and never insert reservation rows:
 * StockReservationService does that after reserve() returns.
 */
interface StockStrategy
{
    public function name(): string;

    /**
     * @param  array<string, int>  $quantities  product_id => quantity, already sorted by product_id ascending
     *
     * @throws \Modules\Inventory\Exceptions\OutOfStock for the first insufficient product in ascending product_id order
     */
    public function reserve(array $quantities): void;
}
