<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Category;

/** @extends Factory<Category> */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $id = (string) Str::uuid7();

        return ['id' => $id, 'parent_id' => null, 'name' => 'Category '.$id, 'slug' => 'category-'.$id, 'position' => 0];
    }
}
