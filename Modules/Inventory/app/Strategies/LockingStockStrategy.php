<?php

declare(strict_types=1);

namespace Modules\Inventory\Strategies;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockStrategy;
use Modules\Inventory\Exceptions\OutOfStock;

/**
 * Pessimistic: SELECT ... WHERE product_id IN (...) ORDER BY product_id FOR UPDATE, check in PHP,
 * then write. The ORDER BY makes every transaction acquire row locks in the same order, which is
 * what rules out deadlocks between orders that list the same products in opposite order.
 * Correct, but the row locks are held for the rest of the order transaction (see ADR 0005).
 */
final class LockingStockStrategy implements StockStrategy
{
    public function name(): string
    {
        return 'locking';
    }

    public function reserve(array $quantities): void
    {
        $rows = DB::table('stock_items')->whereIn('product_id', array_keys($quantities))
            ->orderBy('product_id')->lockForUpdate()->get(['product_id', 'available', 'reserved'])->keyBy('product_id');

        foreach ($quantities as $productId => $quantity) {
            $row = $rows->get($productId);
            if ($row === null || (int) $row->available < $quantity) {
                throw OutOfStock::for($productId);
            }
        }

        foreach ($quantities as $productId => $quantity) {
            $row = $rows->get($productId);
            DB::table('stock_items')->where('product_id', $productId)->update([
                'available' => (int) $row->available - $quantity, // safe: the row is locked by this transaction
                'reserved' => (int) $row->reserved + $quantity,
                'updated_at' => now(),
            ]);
        }
    }
}
