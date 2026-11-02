<?php

declare(strict_types=1);

namespace Modules\Inventory\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Contracts\ProductPricing;
use Modules\Inventory\Services\StockLevels;
use Modules\Shared\Contracts\ProblemException;

final class SetStock extends Command
{
    protected $signature = 'inventory:set-stock {product : Catalog product UUID} {quantity : Absolute stock level (becomes "initial")}';

    protected $description = 'Set initial = quantity and available = quantity - reserved - sold (never touches reserved/sold).';

    public function handle(StockLevels $levels, ProductPricing $pricing): int
    {
        $product = (string) $this->argument('product');
        $quantity = (string) $this->argument('quantity');
        if (! preg_match('/^\d{1,12}$/D', $quantity)) {
            $this->error('quantity must be a non-negative integer.');

            return self::FAILURE;
        }

        try {
            if ($pricing->forProducts([$product]) === []) {
                $this->error('Unknown product: '.$product);

                return self::FAILURE;
            }
            $result = $levels->set($product, (int) $quantity);
        } catch (ProblemException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        $this->table(['product_id', 'initial', 'available', 'reserved', 'sold'], [$result]);

        return self::SUCCESS;
    }
}
