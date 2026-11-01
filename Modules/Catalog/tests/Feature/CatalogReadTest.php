<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Tests\Support\CatalogTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CatalogTesting::configure();
});

it('bounds optimized queries independently of page size and exposes deliberate N plus one', function (): void {
    $category = Category::factory()->create();
    $products = Product::factory()->count(60)->create(['category_id' => $category->id]);
    foreach ($products as $product) {
        $product->images()->create(['path' => 'tests/image.jpg', 'width' => 200, 'height' => 200, 'position' => 0, 'alt' => 'Test image']);
    }
    $counts = [];
    $current = null;
    DB::listen(function (QueryExecuted $event) use (&$counts, &$current): void {
        if ($current !== null && str_starts_with(strtolower(trim($event->sql)), 'select')) {
            $counts[$current] = ($counts[$current] ?? 0) + 1;
        }
    });
    $items = [];
    foreach ([5, 50] as $pageSize) {
        foreach (['optimized' => '/api/v1/products', 'naive' => '/api/v1/_bench/naive/products'] as $kind => $url) {
            Cache::store('cache')->flush();
            $current = $kind.$pageSize;
            $counts[$current] = 0;
            $response = $this->getJson($url.'?per_page='.$pageSize)->assertOk()->assertJsonCount($pageSize, 'data');
            $items[$current] = $response->json('data');
            $current = null;
        }
        expect($counts['optimized'.$pageSize])->toBeLessThanOrEqual(4)
            ->and($counts['naive'.$pageSize])->toBeGreaterThan($pageSize)
            ->and($items['optimized'.$pageSize])->toEqual($items['naive'.$pageSize]);
    }
    expect($counts['optimized5'])->toBe($counts['optimized50'])
        ->and($counts['naive50'])->toBeGreaterThan($counts['naive5'])
        ->and(Model::preventsLazyLoading())->toBeTrue();
});

it('rejects excessive page sizes rather than clamping', function (string $url): void {
    $this->getJson($url.'?per_page=51')->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
})->with(['/api/v1/products', '/api/v1/_bench/naive/products']);

it('rejects malformed tampered and filter-mismatched cursors', function (): void {
    Product::factory()->count(9)->create();
    $cursor = $this->getJson('/api/v1/products?per_page=5')->assertOk()->json('meta.next_cursor');
    expect($cursor)->toBeString();
    $this->getJson('/api/v1/products?per_page=5&cursor=not-a-cursor')->assertStatus(400)->assertHeader('Content-Type', 'application/problem+json');
    $this->getJson('/api/v1/products?per_page=5&cursor='.urlencode($cursor.'x'))->assertStatus(400);
    $this->getJson('/api/v1/products?per_page=5&sort=price_asc&cursor='.urlencode($cursor))->assertStatus(400);
    $this->getJson('/api/v1/products?per_page=5&min_price=10&cursor='.urlencode($cursor))->assertStatus(400);
    $this->getJson('/api/v1/products?per_page=5&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(4, 'data');
});

it('keyset-paginates duplicate prices without duplicate or omitted products', function (string $sort): void {
    Product::factory()->count(13)->create(['price_minor' => 100]);
    $seen = [];
    $cursor = null;
    do {
        $response = $this->getJson('/api/v1/products?'.http_build_query(['per_page' => 5, 'sort' => $sort, 'cursor' => $cursor]))->assertOk();
        array_push($seen, ...array_column($response->json('data'), 'id'));
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);
    expect(count($seen))->toBe(13)->and(count(array_unique($seen)))->toBe(13);
})->with(['newest', 'price_asc', 'price_desc']);

it('hides drafts and archived products publicly, including by slug', function (): void {
    $active = Product::factory()->create();
    foreach (['draft', 'archived'] as $status) {
        $hidden = Product::factory()->create(['status' => $status]);
        $this->getJson('/api/v1/products/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/products/'.$hidden->slug)->assertNotFound();
        $this->withToken(CatalogTesting::token())->getJson('/api/v1/admin/products/'.$hidden->id)->assertOk()->assertJsonPath('data.status', $status);
        $this->getJson('/api/v1/products/'.$hidden->id)->assertNotFound();
    }
    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
});

it('sends public validators without cookies and keeps benchmark responses uncacheable', function (): void {
    Product::factory()->create();
    foreach (['/api/v1/products', '/api/v1/categories'] as $url) {
        $response = $this->getJson($url)->assertOk()->assertHeaderMissing('Set-Cookie');
        expect($response->headers->get('Cache-Control'))->toContain('public', 'max-age=30', 'stale-while-revalidate=60');
        expect($response->headers->get('ETag'))->toStartWith('W/');
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->getJson($url)->assertStatus(304)->assertContent('');
        $this->flushHeaders();
    }
    $this->getJson('/api/v1/_bench/naive/products')->assertOk()->assertHeader('Cache-Control', 'no-store');
});

it('rejects short and overlong search strings before querying SQLite', function (): void {
    $this->getJson('/api/v1/products?q=ab')->assertStatus(422);
    $this->getJson('/api/v1/products?q='.str_repeat('a', 101))->assertStatus(422);
    $this->getJson('/api/v1/products?min_price=100&max_price=20')->assertStatus(422);
});

it('returns position-ordered category children', function (): void {
    $root = Category::factory()->create();
    Category::factory()->create(['parent_id' => $root->id, 'position' => 2, 'name' => 'Second']);
    Category::factory()->create(['parent_id' => $root->id, 'position' => 1, 'name' => 'First']);
    $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.children.0.name', 'First');
});
