<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Product;

/** @extends Factory<Product> */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $id = (string) Str::uuid7();

        return [
            'id' => $id, 'category_id' => CategoryFactory::new(),
            'sku' => 'TEST-'.strtoupper($id), 'name' => 'Test product '.$id, 'slug' => 'product-'.$id,
            'description' => 'A catalog test fixture.', 'price_minor' => 125000,
            'currency' => config('catalog.currency'), 'status' => 'active',
        ];
    }
}
