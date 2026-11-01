<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Catalog\Http\Requests\ListProductsRequest;
use Modules\Catalog\Http\Resources\ProductResource;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Services\PageCache;
use Modules\Catalog\Services\ProductQuery;
use Modules\Catalog\Support\CursorCodec;
use Modules\Catalog\Support\PublicResponse;

#[Group('Catalog')]
final class ProductController
{
    /**
     * List active products.
     *
     * UUIDv7 keyset pagination. Prices are minor units of catalog.currency. Above-max
     * per_page is rejected (422); a bad or filter-mismatched cursor is 400. q is an
     * escaped, case-insensitive substring and skips server cache. Weak ETag supports
     * If-None-Match / 304; public max-age=30 and stale-while-revalidate=60; no cookies.
     *
     * @unauthenticated
     */
    public function index(ListProductsRequest $request, ProductQuery $query, PageCache $cache, CursorCodec $cursor): JsonResponse
    {
        $params = $request->normalized();
        $cursor->decode($params); // Validate before consulting the cache.
        $payload = $cache->remember('list', $params, fn (): array => $query->page($params, $request));

        return PublicResponse::make($request, $payload);
    }

    /**
     * Get an active product by UUID or slug.
     *
     * Draft/archived products return 404 even for an authenticated administrator.
     * Includes all image metadata, not an upload endpoint.
     *
     * @unauthenticated
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        $product = Product::query()->select(ProductQuery::COLUMNS)->whereRaw("status = 'active'")
            ->where(Str::isUuid($identifier) ? 'id' : 'slug', $identifier)
            ->with([...ProductQuery::RELATIONS, 'images:id,product_id,path,width,height,position,alt'])->first();
        if ($product === null) {
            throw new CatalogProblem('Product not found.', 404, 'not-found');
        }

        return PublicResponse::make($request, ['data' => (new ProductResource($product))->resolve($request)]);
    }
}
