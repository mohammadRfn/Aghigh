<?php

declare(strict_types=1);

namespace Modules\Inventory\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Support\UnsafeEnvironment;

/**
 * Load-test fixture: give every catalog product the same absolute stock level.
 * Non-production only. Reads product ids straight from the products table (ids only, ADR 0001);
 * Inventory still never imports Catalog internals.
 */
final class SeedStock extends Command
{
    protected $signature = 'inventory:seed-stock {--quantity=1000 : Stock level for every product} {--chunk=1000 : Products per upsert}';

    protected $description = 'Give every product the same stock level (local/testing/loadtest only).';

    public function handle(): int
    {
        if (! UnsafeEnvironment::allows((string) app()->environment())) {
            $this->error('inventory:seed-stock runs only in local, testing or loadtest.');

            return self::FAILURE;
        }
        $quantity = (int) $this->option('quantity');
        if ($quantity < 0 || $quantity > (int) config('inventory.max_stock')) {
            $this->error('--quantity must be between 0 and '.config('inventory.max_stock').'.');

            return self::FAILURE;
        }
        $chunk = max(100, min((int) $this->option('chunk'), 5000));
        $seeded = 0;

        DB::table('products')->select('id')->orderBy('id')->chunkById($chunk, function (Collection $products) use ($quantity, &$seeded): void {
            $now = now();
            $rows = $products->map(fn (object $product): array => [
                'product_id' => (string) $product->id, 'initial' => $quantity, 'available' => $quantity,
                'reserved' => 0, 'sold' => 0, 'created_at' => $now, 'updated_at' => $now,
            ])->all();
            // Existing rows keep reserved/sold; a level below what is already allocated fails on the CHECK (fixtures only).
            DB::table('stock_items')->upsert($rows, ['product_id'], [
                'initial',
                'available' => DB::raw('excluded.initial - stock_items.reserved - stock_items.sold'),
                'updated_at',
            ]);
            $seeded += count($rows);
        });

        $this->info("Stock set to {$quantity} for {$seeded} products.");

        return self::SUCCESS;
    }
}
