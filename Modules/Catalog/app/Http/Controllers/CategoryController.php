<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Services\PageCache;
use Modules\Catalog\Support\PublicResponse;

#[Group('Catalog')]
final class CategoryController
{
    /**
     * Get the category tree, ordered by position then UUID.
     *
     * Versioned cache with stale-while-revalidate. ETag / 304 supported. No cookies.
     *
     * @unauthenticated
     */
    public function index(Request $request, PageCache $cache): JsonResponse
    {
        $payload = $cache->remember('categories', [], function (): array {
            $groups = Category::query()->select(['id', 'parent_id', 'name', 'slug', 'position'])
                ->orderBy('position')->orderBy('id')->get()->groupBy(fn (Category $category): string => $category->parent_id ?? 'root');
            $tree = function (string $parent, int $depth = 1) use (&$tree, $groups): array {
                if ($depth > (int) config('catalog.category_max_depth')) {
                    return [];
                }

                return $groups->get($parent, collect())->map(fn (Category $category): array => [
                    'id' => $category->id, 'name' => $category->name, 'slug' => $category->slug,
                    'position' => $category->position, 'children' => $tree($category->id, $depth + 1),
                ])->values()->all();
            };

            return ['data' => $tree('root')];
        });

        return PublicResponse::make($request, $payload);
    }
}
