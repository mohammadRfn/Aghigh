<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Contracts\ProductPricing;
use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Catalog\Models\Product;
use Tests\Support\CatalogTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CatalogTesting::configure();
});

it('omits unknown IDs returns exact DTOs and flags inactive products', function (): void {
    $active = Product::factory()->create(['price_minor' => 123456]);
    $draft = Product::factory()->create(['status' => 'draft']);
    $archived = Product::factory()->create(['status' => 'archived']);
    $unknown = (string) Str::uuid7();
    $prices = app(ProductPricing::class)->forProducts([$active->id, $draft->id, $archived->id, $unknown]);
    expect(array_keys($prices))->toHaveCount(3)
        ->and($prices)->not->toHaveKey($unknown)
        ->and($prices[$active->id]->price_minor)->toBe(123456)
        ->and($prices[$active->id]->currency)->toBe(config('catalog.currency'))
        ->and($prices[$active->id]->active)->toBeTrue()
        ->and($prices[$draft->id]->active)->toBeFalse()
        ->and($prices[$archived->id]->active)->toBeFalse();
});

it('ignores stale cache values and all cache dependencies', function (): void {
    $product = Product::factory()->create(['price_minor' => 100]);
    $this->getJson('/api/v1/products')->assertOk();
    DB::table('products')->where('id', $product->id)->update(['price_minor' => 200]);
    Cache::shouldReceive('store')->never();
    expect(app(ProductPricing::class)->forProducts([$product->id])[$product->id]->price_minor)->toBe(200);
});

it('enforces the input cap before deduplication and validates UUIDs', function (): void {
    $id = (string) Str::uuid7();
    expect(fn () => app(ProductPricing::class)->forProducts(array_fill(0, (int) config('catalog.pricing_max_ids') + 1, $id)))->toThrow(CatalogProblem::class);
    expect(fn () => app(ProductPricing::class)->forProducts(['invalid']))->toThrow(CatalogProblem::class);
    expect(app(ProductPricing::class)->forProducts([]))->toBe([]);
});
