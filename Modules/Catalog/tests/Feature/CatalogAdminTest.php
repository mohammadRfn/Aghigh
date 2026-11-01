<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Tests\Support\CatalogTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CatalogTesting::configure();
});

it('enforces anonymous customer and admin authorization before target lookup or validation', function (string $role, int $status): void {
    $product = Product::factory()->create(['status' => 'draft']);
    $category = Category::factory()->create();
    if ($role !== 'anonymous') {
        $this->withToken(CatalogTesting::token($role));
    }
    $payload = ['category_id' => $category->id, 'sku' => 'NEW-1', 'slug' => 'new-product', 'name' => 'New product', 'price_minor' => 1000, 'currency' => config('catalog.currency'), 'status' => 'active'];
    $this->postJson('/api/v1/admin/products', $payload)->assertStatus($status === 200 ? 201 : $status);
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['name' => 'Updated'])->assertStatus($status);
    $this->getJson('/api/v1/admin/products/'.$product->id)->assertStatus($status);
    $this->postJson('/api/v1/admin/categories', ['name' => 'New category', 'slug' => 'new-category'])->assertStatus($status === 200 ? 201 : $status);
    $this->patchJson('/api/v1/admin/categories/'.$category->id, ['position' => 3])->assertStatus($status);
})->with([['anonymous', 401], ['customer', 403], ['admin', 200]]);

it('uses private no-store for admin successes and errors', function (): void {
    $product = Product::factory()->create();
    $response = $this->getJson('/api/v1/admin/products/'.$product->id)->assertUnauthorized();
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $response = $this->withToken(CatalogTesting::token())->getJson('/api/v1/admin/products/'.$product->id)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

it('invalidates product pages after admin writes', function (): void {
    $product = Product::factory()->create();
    $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', $product->name);
    $before = Cache::store('state')->get('catalog:versions:products');
    $this->withToken(CatalogTesting::token())->patchJson('/api/v1/admin/products/'.$product->id, ['name' => 'New public name'])->assertOk();
    expect(Cache::store('state')->get('catalog:versions:products'))->toBeGreaterThan($before);
    $this->getJson('/api/v1/products')->assertJsonPath('data.0.name', 'New public name');
});

it('invalidates both category trees and embedded product categories', function (): void {
    $category = Category::factory()->create(['name' => 'Before']);
    Product::factory()->create(['category_id' => $category->id]);
    $this->getJson('/api/v1/products')->assertJsonPath('data.0.category.name', 'Before');
    $this->getJson('/api/v1/categories')->assertJsonPath('data.0.name', 'Before');
    $this->withToken(CatalogTesting::token())->patchJson('/api/v1/admin/categories/'.$category->id, ['name' => 'After'])->assertOk();
    $this->getJson('/api/v1/products')->assertJsonPath('data.0.category.name', 'After');
    $this->getJson('/api/v1/categories')->assertJsonPath('data.0.name', 'After');
});

it('validates bounded integer money catalog currency and uniqueness', function (): void {
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    $this->withToken(CatalogTesting::token());
    foreach ([['price_minor' => 0], ['price_minor' => -1], ['price_minor' => '1.5'], ['price_minor' => '9223372036854775807'], ['currency' => 'ZZZ'], ['sku' => $other->sku], ['slug' => $other->slug], ['slug' => '../bad'], ['sku' => 'bad sku']] as $payload) {
        $this->patchJson('/api/v1/admin/products/'.$product->id, $payload)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
    }
});

it('replaces images as a set and rejects invalid metadata without partial writes', function (): void {
    $product = Product::factory()->create();
    $product->images()->create(['path' => 'old.jpg', 'width' => 10, 'height' => 10, 'position' => 0, 'alt' => 'Old']);
    $this->withToken(CatalogTesting::token());
    $image = ['path' => 'catalog/new.jpg', 'width' => 1200, 'height' => 1200, 'position' => 1, 'alt' => 'New'];
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['images' => [$image]])->assertOk()->assertJsonCount(1, 'data.images')->assertJsonPath('data.images.0.path', 'catalog/new.jpg');
    foreach ([['path' => '../escape.jpg'], ['width' => 0], ['height' => -1], ['position' => -1], ['path' => 'https://host/file.jpg']] as $invalid) {
        $this->patchJson('/api/v1/admin/products/'.$product->id, ['name' => 'Should not change', 'images' => [array_replace($image, $invalid)]])->assertStatus(422);
    }
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['images' => array_fill(0, (int) config('catalog.images_max') + 1, $image)])->assertStatus(422);
    expect($product->fresh()->name)->toBe($product->name);
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['images' => []])->assertOk()->assertJsonCount(0, 'data.images');
});

it('prevents category cycles and checks the depth of a moved subtree', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->create(['parent_id' => $a->id]);
    $c = Category::factory()->create(['parent_id' => $b->id]);
    $this->withToken(CatalogTesting::token());
    $this->patchJson('/api/v1/admin/categories/'.$a->id, ['parent_id' => $c->id])->assertStatus(422);
    $this->patchJson('/api/v1/admin/categories/'.$a->id, ['parent_id' => $a->id])->assertStatus(422);
    config(['catalog.category_max_depth' => 3]);
    $root = Category::factory()->create();
    $this->patchJson('/api/v1/admin/categories/'.$a->id, ['parent_id' => $root->id])->assertStatus(422);
    expect($a->fresh()->parent_id)->toBeNull();
});

it('requires archived products to pass through draft before publication', function (): void {
    $product = Product::factory()->create(['status' => 'archived']);
    $this->withToken(CatalogTesting::token())->patchJson('/api/v1/admin/products/'.$product->id, ['status' => 'active'])->assertStatus(422);
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['status' => 'draft'])->assertOk();
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['status' => 'active'])->assertOk();
});
