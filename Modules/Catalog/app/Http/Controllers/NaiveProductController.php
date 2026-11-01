<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Modules\Catalog\Http\Requests\ListProductsRequest;
use Modules\Catalog\Http\Resources\ProductResource;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Support\CursorCodec;

#[Group('_bench')]
final class NaiveProductController
{
    /**
     * Deliberately inefficient catalog baseline.
     *
     * Available ONLY in local, testing, loadtest. No cache; no-store. Same validated
     * filters and resource contract as Catalog. Cursor wraps a signed offset. The
     * non-indexed status/category/price expressions defeat optimized partial indexes.
     * N+1 is deliberate; lazy-loading prevention is restored in finally.
     *
     * @unauthenticated
     */
    public function index(ListProductsRequest $request, CursorCodec $cursor): JsonResponse
    {
        $params = $request->normalized();
        $position = $cursor->decode($params, 'naive');
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading(false);
        try {
            $query = Product::query()->select('*')->whereRaw("(status || '') = 'active'");
            if ($params['category'] !== null) {
                $query->whereRaw('CAST(category_id AS TEXT) = ?', [$params['category']]);
            }
            foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
                if ($params[$key] !== null) {
                    $query->whereRaw("(price_minor + 0) {$operator} ?", [$params[$key]]);
                }
            }
            if ($params['q'] !== null) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $params['q']);
                $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
                $query->whereRaw("name {$operator} ? ESCAPE '\\'", ['%'.$escaped.'%']);
            }
            if ($params['sort'] === 'newest') {
                // An unindexed computed column preserves exactly the optimized UUID order
                // while defeating the UUID B-tree's ordering. Tables remain shared.
                $query->selectRaw('CAST(id AS TEXT) AS naive_sort_key')->orderByDesc('naive_sort_key');
            } else {
                $direction = $params['sort'] === 'price_asc' ? 'ASC' : 'DESC';
                $query->orderByRaw("(price_minor + 0) {$direction}, CAST(id AS TEXT) {$direction}");
            }
            $rows = $query->offset($position['offset'] ?? 0)->limit($params['per_page'] + 1)->get();
            $hasMore = $rows->count() > $params['per_page'];
            $rows = $rows->take($params['per_page'])->values();
            foreach ($rows as $product) {
                $product->getRelationValue('category');
                $images = $product->getRelationValue('images');
                $product->setRelation('primaryImage', $images->first());
                $product->unsetRelation('images');
            }
            $last = $rows->last();
            $next = $hasMore && $last !== null ? $cursor->encode($params, [
                'id' => $last->id, 'price_minor' => $last->price_minor, 'offset' => ($position['offset'] ?? 0) + $params['per_page'],
            ], 'naive') : null;

            return response()->json(['data' => ProductResource::collection($rows)->resolve($request), 'meta' => ['per_page' => $params['per_page'], 'next_cursor' => $next]])
                ->header('Cache-Control', 'no-store');
        } finally {
            Model::preventLazyLoading($previous);
        }
    }
}
