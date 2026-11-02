<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Exceptions\InventoryProblem;

/**
 * Absolute stock levels (admin PUT and inventory:set-stock). Sets initial = quantity and
 * available = quantity - reserved - sold, creates the row if missing, never touches reserved or sold,
 * and refuses (422) when available would go negative. Locks the row so a concurrent reservation
 * cannot slip between the read and the write.
 */
final class StockLevels
{
    /** @return array{product_id: string, initial: int, available: int, reserved: int, sold: int} */
    public function set(string $productId, int $quantity): array
    {
        $productId = strtolower($productId);
        if (! Str::isUuid($productId)) {
            throw new InventoryProblem('The product id must be a UUID.');
        }
        $max = (int) config('inventory.max_stock');
        if ($quantity < 0 || $quantity > $max) {
            throw new InventoryProblem('Quantity must be an integer between 0 and '.$max.'.');
        }

        return DB::transaction(function () use ($productId, $quantity): array {
            $row = DB::table('stock_items')->where('product_id', $productId)->lockForUpdate()->first();
            if ($row === null) {
                DB::table('stock_items')->insertOrIgnore([
                    'product_id' => $productId, 'initial' => 0, 'available' => 0, 'reserved' => 0, 'sold' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('stock_items')->where('product_id', $productId)->lockForUpdate()->first();
            }
            $reserved = (int) $row->reserved;
            $sold = (int) $row->sold;
            $available = $quantity - $reserved - $sold;
            if ($available < 0) {
                throw new InventoryProblem(
                    sprintf('Quantity %d is below the %d units already reserved or sold.', $quantity, $reserved + $sold),
                    422,
                    'stock-below-allocated',
                    ['product_id' => $productId, 'reserved' => $reserved, 'sold' => $sold],
                );
            }
            DB::table('stock_items')->where('product_id', $productId)->update([
                'initial' => $quantity, 'available' => $available, 'updated_at' => now(),
            ]);

            return ['product_id' => $productId, 'initial' => $quantity, 'available' => $available, 'reserved' => $reserved, 'sold' => $sold];
        });
    }

    /** @return array{product_id: string, initial: int, available: int, reserved: int, sold: int} */
    public function get(string $productId): array
    {
        $row = DB::table('stock_items')->where('product_id', strtolower($productId))->first();

        return [
            'product_id' => strtolower($productId),
            'initial' => (int) ($row->initial ?? 0),
            'available' => (int) ($row->available ?? 0),
            'reserved' => (int) ($row->reserved ?? 0),
            'sold' => (int) ($row->sold ?? 0),
        ];
    }
}
