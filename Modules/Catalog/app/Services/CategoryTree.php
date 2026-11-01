<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Catalog\Models\Category;

final class CategoryTree
{
    /** @param array<string, mixed> $data */
    public function save(?string $id, array $data): Category
    {
        // PostgreSQL transaction-scoped serialization also protects against an
        // expired Redis lease. PgBouncer transaction pooling is supported.
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(1684108385, 1)');
        }
        $category = $id === null ? new Category : Category::query()->lockForUpdate()->find($id);
        if ($category === null) {
            throw new CatalogProblem('Category not found.', 404, 'not-found');
        }
        if (! $category->exists) {
            $category->id = (string) Str::uuid7();
            $category->parent_id = null;
            $category->position = 0;
        }
        $category->fill($data);
        $parents = Category::query()->pluck('parent_id', 'id')->all();
        $parents[$category->id] = $category->parent_id;
        foreach (array_keys($parents) as $node) {
            $seen = [];
            $depth = 0;
            while ($node !== null) {
                if (isset($seen[$node])) {
                    throw new CatalogProblem('Category parent changes cannot introduce a cycle.');
                }
                $seen[$node] = true;
                if (++$depth > (int) config('catalog.category_max_depth')) {
                    throw new CatalogProblem('Category tree exceeds the configured maximum depth.');
                }
                if (! array_key_exists($node, $parents)) {
                    throw new CatalogProblem('Category parent does not exist.');
                }
                $node = $parents[$node];
            }
        }
        $category->save();

        return $category;
    }
}
