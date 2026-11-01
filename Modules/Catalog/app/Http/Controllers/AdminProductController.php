<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Catalog\Http\Requests\WriteProductRequest;
use Modules\Catalog\Http\Resources\ProductResource;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Policies\CatalogPolicy;
use Modules\Catalog\Services\CatalogWriter;
use Modules\Catalog\Services\ProductQuery;
use Modules\Identity\Contracts\CurrentUser;

#[Group('Catalog admin')]
final class AdminProductController
{
    /** Get a product in any status. Requires an admin JWT; private, no-store. */
    public function show(Request $request, string $id, CurrentUser $actor, CatalogPolicy $policy): JsonResponse
    {
        $policy->authorize($actor);
        $product = Product::query()->find($id);
        if ($product === null) {
            throw new CatalogProblem('Product not found.', 404, 'not-found');
        }

        return $this->response($request, $product);
    }

    /**
     * Create a product and image metadata atomically. Admin only.
     *
     * Integer minor units and the catalog currency are required. Images are relative
     * paths, not uploads. UUIDv7 generated in PHP. Successful writes bump versions
     * and emit an audit event containing field names, never the request body.
     */
    public function store(WriteProductRequest $request, CurrentUser $actor, CatalogWriter $writer): JsonResponse
    {
        $data = $request->validated();
        $product = $writer->run($actor, 'product', array_keys($data), function () use ($data): Product {
            $images = $data['images'] ?? [];
            unset($data['images']);
            $product = Product::query()->create($data);
            $product->images()->createMany($images);

            return $product;
        });

        return $this->response($request, $product, 201);
    }

    /**
     * Update a product. Admin only; no hard delete.
     *
     * images replaces the complete set in the same short transaction. Omit images
     * to keep them, [] to clear them. Archived products must return to draft before
     * becoming active. Rows are locked before checking the transition.
     */
    public function update(WriteProductRequest $request, string $id, CurrentUser $actor, CatalogWriter $writer): JsonResponse
    {
        $data = $request->validated();
        $product = $writer->run($actor, 'product', array_keys($data), function () use ($id, $data): Product {
            $product = Product::query()->lockForUpdate()->find($id);
            if ($product === null) {
                throw new CatalogProblem('Product not found.', 404, 'not-found');
            }
            if ($product->status === 'archived' && ($data['status'] ?? 'archived') === 'active') {
                throw new CatalogProblem('Restore an archived product to draft before publishing it.');
            }
            $images = $data['images'] ?? null;
            unset($data['images']);
            $product->fill($data)->save();
            if ($images !== null) {
                $product->images()->delete();
                $product->images()->createMany($images);
            }

            return $product;
        });

        return $this->response($request, $product);
    }

    private function response(Request $request, Product $product, int $status = 200): JsonResponse
    {
        $product->refresh()->load([...ProductQuery::RELATIONS, 'images:id,product_id,path,width,height,position,alt']);
        $payload = (new ProductResource($product))->resolve($request);
        $payload['status'] = $product->status;
        $payload['category_id'] = $product->category_id;

        return response()->json(['data' => $payload], $status)->header('Cache-Control', 'private, no-store');
    }
}
