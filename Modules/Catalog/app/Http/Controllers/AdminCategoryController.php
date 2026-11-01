<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Modules\Catalog\Http\Requests\WriteCategoryRequest;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Services\CatalogWriter;
use Modules\Catalog\Services\CategoryTree;
use Modules\Identity\Contracts\CurrentUser;

#[Group('Catalog admin')]
final class AdminCategoryController
{
    /** Create a category. Admin JWT; parent depth bounded; private, no-store. */
    public function store(WriteCategoryRequest $request, CurrentUser $actor, CatalogWriter $writer, CategoryTree $tree): JsonResponse
    {
        $data = $request->validated();
        $category = $writer->run($actor, 'category', array_keys($data), fn (): Category => $tree->save(null, $data));

        return $this->response($category, 201);
    }

    /**
     * Update a category, including its parent or position.
     *
     * The complete resulting tree is checked for cycles and depth, including the
     * moved subtree. PostgreSQL uses a transaction advisory lock. Admin JWT required.
     */
    public function update(WriteCategoryRequest $request, string $id, CurrentUser $actor, CatalogWriter $writer, CategoryTree $tree): JsonResponse
    {
        $data = $request->validated();
        $category = $writer->run($actor, 'category', array_keys($data), fn (): Category => $tree->save($id, $data));

        return $this->response($category);
    }

    private function response(Category $category, int $status = 200): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $category->id, 'parent_id' => $category->parent_id, 'name' => $category->name,
            'slug' => $category->slug, 'position' => $category->position,
        ]], $status)->header('Cache-Control', 'private, no-store');
    }
}
