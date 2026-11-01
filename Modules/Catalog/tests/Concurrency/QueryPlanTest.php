<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Services\ProductQuery;

it('asks the real planner to use catalog indexes and shows sequential naive scans', function (): void {
    $this->artisan('catalog:seed-bulk', ['--products' => 60000, '--categories' => 200, '--images-per-product' => 1, '--truncate' => true])->assertSuccessful();
    DB::statement('ANALYZE categories, products, product_images');
    $category = Category::query()->where('slug', 'bulk-category-199')->value('id');
    $base = ['category' => null, 'q' => null, 'min_price' => null, 'max_price' => null, 'sort' => 'newest', 'per_page' => 20, 'cursor' => null];
    $queries = app(ProductQuery::class);
    $shapes = [
        'category' => [$queries->optimized(array_replace($base, ['category' => $category]))->orderByDesc('id')->limit(20), 'catalog_active_category_id'],
        'price asc' => [$queries->optimized($base)->orderBy('price_minor')->orderBy('id')->limit(20), 'catalog_active_price_id'],
        'price desc' => [$queries->optimized($base)->orderByDesc('price_minor')->orderByDesc('id')->limit(20), 'catalog_active_price_id'],
        'category price' => [$queries->optimized(array_replace($base, ['category' => $category]))->orderBy('price_minor')->orderBy('id')->limit(20), 'catalog_active_category_price_id'],
        'Persian trigram' => [$queries->optimized(array_replace($base, ['q' => 'زعفران collection 12']))->orderByDesc('id')->limit(20), 'catalog_active_name_trgm'],
    ];
    foreach ($shapes as $label => [$query, $expected]) {
        /** @var Builder $query */
        $row = (array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());
        $plan = (string) $row['QUERY PLAN'];
        // Do not force enable_seqscan=off. A planner difference is visible evidence,
        // not something to hide with a retry. See docs/performance-notes.md.
        $this->assertStringContainsString($expected, $plan, $label.' plan: '.$plan);
    }
    foreach ([
        "SELECT *, CAST(id AS TEXT) AS naive_sort_key FROM products WHERE (status || '') = 'active' ORDER BY naive_sort_key DESC LIMIT 20 OFFSET 40000",
        "SELECT *, CAST(id AS TEXT) AS naive_sort_key FROM products WHERE (status || '') = 'active' AND name ILIKE '%زعفران%' ORDER BY naive_sort_key DESC LIMIT 20",
        "SELECT * FROM products WHERE (status || '') = 'active' ORDER BY (price_minor + 0), CAST(id AS TEXT) LIMIT 20",
    ] as $sql) {
        $plan = (array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql);
        expect((string) $plan['QUERY PLAN'])->toContain('Seq Scan');
    }
});

it('uses PostgreSQL ofMany with UUID ties and escaped Persian search', function (): void {
    $this->artisan('catalog:seed-bulk', ['--products' => 300, '--categories' => 12, '--images-per-product' => 3, '--truncate' => true])->assertSuccessful();
    $this->getJson('/api/v1/products?per_page=5')->assertOk()->assertJsonPath('data.0.primary_image.position', 0);
    $response = $this->getJson('/api/v1/products?'.http_build_query(['q' => 'زعفران']))->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
    foreach ($response->json('data') as $product) {
        expect($product['name'])->toContain('زعفران');
    }
    $this->getJson('/api/v1/products?'.http_build_query(['q' => '%__']))->assertOk()->assertJsonCount(0, 'data');
});
