<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Contracts\ProductPricing;
use Modules\Catalog\DTOs\ProductPrice;
use Modules\Catalog\Exceptions\CatalogProblem;

final class DatabasePricing implements ProductPricing
{
    public function forProducts(array $ids): array
    {
        if (count($ids) > (int) config('catalog.pricing_max_ids')) {
            throw new CatalogProblem('Too many product IDs in the pricing request.');
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new CatalogProblem('Pricing IDs must be UUIDs.');
            }
        }
        if ($ids === []) {
            return [];
        }
        $prices = [];
        foreach (DB::table('products')->whereIn('id', array_unique($ids))->get(['id', 'price_minor', 'currency', 'status']) as $product) {
            $prices[(string) $product->id] = new ProductPrice((int) $product->price_minor, (string) $product->currency, $product->status === 'active');
        }

        return $prices;
    }
}
