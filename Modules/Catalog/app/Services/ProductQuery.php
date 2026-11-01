<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Catalog\Http\Resources\ProductResource;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Support\CursorCodec;

final class ProductQuery
{
    public const COLUMNS = ['id', 'category_id', 'sku', 'name', 'slug', 'description', 'price_minor', 'currency'];

    public const RELATIONS = [
        'category:id,name,slug',
        'primaryImage:product_images.id,product_images.product_id,product_images.path,product_images.width,product_images.height,product_images.position,product_images.alt',
    ];

    public function __construct(private readonly CursorCodec $cursor) {}

    /**
     * @param  array<string, mixed>  $params
     * @return Builder<Product>
     */
    public function optimized(array $params): Builder
    {
        $query = Product::query()->select(self::COLUMNS)->whereRaw("status = 'active'");
        if ($params['category'] !== null) {
            $query->where('category_id', $params['category']);
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if ($params[$key] !== null) {
                $query->where('price_minor', $operator, $params[$key]);
            }
        }
        if ($params['q'] !== null) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $params['q']);
            // The expression and literal active predicate match the partial GIN index.
            $query->whereRaw("lower(name) LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function page(array $params, Request $request): array
    {
        $position = $this->cursor->decode($params);
        $query = $this->optimized($params)->with(self::RELATIONS);
        if ($params['sort'] === 'newest') {
            if ($position !== null) {
                $query->where('id', '<', $position['id']);
            }
            $query->orderByDesc('id');
        } else {
            $ascending = $params['sort'] === 'price_asc';
            $direction = $ascending ? 'asc' : 'desc';
            $operator = $ascending ? '>' : '<';
            if ($position !== null) {
                // Tuple comparison exposes the composite B-tree seek boundary directly.
                $query->whereRaw("(price_minor, id) {$operator} (?, ?)", [$position['price_minor'], $position['id']]);
            }
            $query->orderBy('price_minor', $direction)->orderBy('id', $direction);
        }
        $rows = $query->limit($params['per_page'] + 1)->get();
        $hasMore = $rows->count() > $params['per_page'];
        $rows = $rows->take($params['per_page'])->values();
        $last = $rows->last();
        $next = $hasMore && $last !== null ? $this->cursor->encode($params, [
            'id' => $last->id, 'price_minor' => $last->price_minor,
            'offset' => ($position['offset'] ?? 0) + $params['per_page'],
        ]) : null;

        return ['data' => ProductResource::collection($rows)->resolve($request), 'meta' => ['per_page' => $params['per_page'], 'next_cursor' => $next]];
    }
}
