<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

use Modules\Catalog\DTOs\ProductPrice;

interface ProductPricing
{
    /**
     * Exact order-time prices, read directly from the database. Unknown IDs omitted.
     * Inactive products are returned with active=false. The input count (including
     * duplicates) is capped before deduplication to bound work.
     *
     * @param list<string> $ids
     * @return array<string, ProductPrice>
     */
    public function forProducts(array $ids): array;
}
