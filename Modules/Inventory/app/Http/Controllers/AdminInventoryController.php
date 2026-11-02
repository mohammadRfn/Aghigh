<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Contracts\ProductPricing;
use Modules\Identity\Contracts\CurrentUser;
use Modules\Inventory\Exceptions\InventoryProblem;
use Modules\Inventory\Policies\InventoryPolicy;
use Modules\Inventory\Services\StockLevels;

#[Group('Inventory admin')]
final class AdminInventoryController
{
    /** Current counters of a product (zeros when no stock row exists yet). Admin only; private, no-store. */
    public function show(string $product, CurrentUser $actor, InventoryPolicy $policy, ProductPricing $pricing, StockLevels $levels): JsonResponse
    {
        $policy->authorize($actor);
        $this->ensureProduct($pricing, $product);

        return new JsonResponse(['data' => $levels->get($product)]);
    }

    /**
     * Set the absolute stock level of a product. Admin only; private, no-store.
     *
     * Sets initial = quantity and available = quantity - reserved - sold; reserved and sold are never
     * touched. 422 "stock-below-allocated" when available would become negative.
     */
    public function update(Request $request, string $product, CurrentUser $actor, InventoryPolicy $policy, ProductPricing $pricing, StockLevels $levels): JsonResponse
    {
        $policy->authorize($actor); // authorization before validation or lookup (same order as Catalog admin)
        $this->ensureProduct($pricing, $product);
        /** @var array{quantity: int} $data */
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.(int) config('inventory.max_stock')]]);

        return new JsonResponse(['data' => $levels->set($product, (int) $data['quantity'])]);
    }

    private function ensureProduct(ProductPricing $pricing, string $product): void
    {
        if ($pricing->forProducts([$product]) === []) {
            throw new InventoryProblem('Product not found.', 404, 'not-found');
        }
    }
}
