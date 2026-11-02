<?php

declare(strict_types=1);

namespace Modules\Inventory\Strategies;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockStrategy;
use Modules\Inventory\Exceptions\OutOfStock;

/**
 * Default. One conditional UPDATE per product, in ascending product_id order:
 *   UPDATE stock_items SET available = available - ?, reserved = reserved + ?
 *   WHERE product_id = ? AND available >= ?
 * Rows affected decides. Postgres serializes writers on the row lock and re-evaluates the WHERE
 * clause on the committed version, so the check and the write are one atomic step and no lock is
 * held beyond the statement (plus the enclosing short transaction).
 */
final class AtomicStockStrategy implements StockStrategy
{
    public function name(): string
    {
        return 'atomic';
    }

    public function reserve(array $quantities): void
    {
        foreach ($quantities as $productId => $quantity) {
            $affected = DB::table('stock_items')
                ->where('product_id', $productId)
                ->where('available', '>=', $quantity)
                ->update([
                    'available' => DB::raw('available - '.$quantity),
                    'reserved' => DB::raw('reserved + '.$quantity),
                    'updated_at' => now(),
                ]);
            if ($affected !== 1) {
                throw OutOfStock::for($productId);
            }
        }
    }
}
