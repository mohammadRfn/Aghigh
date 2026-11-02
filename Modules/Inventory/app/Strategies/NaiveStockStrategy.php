<?php

declare(strict_types=1);

namespace Modules\Inventory\Strategies;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockStrategy;
use Modules\Inventory\Exceptions\OutOfStock;

/**
 * DELIBERATELY BROKEN (local/testing/loadtest only): read without a lock, check in PHP, write
 * absolute values. Two concurrent requests both read available=5 and both write available=4:
 * a classic lost update. Reservation rows then exceed the stock, which is exactly what
 * orders:verify-invariants detects. STOCK_NAIVE_DELAY_MS widens the read->write window so the
 * oversell is reproducible in the Concurrency suite (it is a test aid, never a latency figure).
 */
final class NaiveStockStrategy implements StockStrategy
{
    public function name(): string
    {
        return 'naive';
    }

    public function reserve(array $quantities): void
    {
        $rows = DB::table('stock_items')->whereIn('product_id', array_keys($quantities))
            ->orderBy('product_id')->get(['product_id', 'available', 'reserved'])->keyBy('product_id');

        foreach ($quantities as $productId => $quantity) {
            $row = $rows->get($productId);
            if ($row === null || (int) $row->available < $quantity) {
                throw OutOfStock::for($productId);
            }
        }

        $delay = (int) config('inventory.naive_delay_ms');
        if ($delay > 0) {
            usleep($delay * 1000);
        }

        foreach ($quantities as $productId => $quantity) {
            $row = $rows->get($productId);
            DB::table('stock_items')->where('product_id', $productId)->update([
                'available' => (int) $row->available - $quantity, // absolute: overwrites concurrent writers
                'reserved' => (int) $row->reserved + $quantity,
                'updated_at' => now(),
            ]);
        }
    }
}
