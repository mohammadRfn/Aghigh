<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('bulk seeds expected rows and refuses nonempty tables unless explicitly truncated', function (): void {
    $options = ['--categories' => 12, '--products' => 120, '--images-per-product' => 2, '--truncate' => true];
    $this->artisan('catalog:seed-bulk', $options)->assertSuccessful();
    expect(DB::table('categories')->count())->toBe(12)
        ->and(DB::table('products')->count())->toBe(120)
        ->and(DB::table('product_images')->count())->toBe(240);
    $unique = (array) DB::selectOne('SELECT count(DISTINCT sku) AS skus, count(DISTINCT slug) AS slugs FROM products');
    expect((int) $unique['skus'])->toBe(120)->and((int) $unique['slugs'])->toBe(120);
    $this->artisan('catalog:seed-bulk', ['--categories' => 12, '--products' => 120, '--images-per-product' => 2])->assertFailed();
    expect(DB::table('products')->count())->toBe(120);
    $this->artisan('catalog:seed-bulk', $options)->assertSuccessful();
    expect(DB::table('products')->count())->toBe(120)->and(DB::table('product_images')->count())->toBe(240);
    expect(DB::table('products')->whereRaw("substring(id::text, 15, 1) <> '7'")->count())->toBe(0);
    expect(DB::table('products')->where('currency', '<>', config('catalog.currency'))->count())->toBe(0);
});

it('refuses bulk seeding in production before touching tables', function (): void {
    app()->instance('env', 'production');
    try {
        $this->artisan('catalog:seed-bulk', ['--truncate' => true])->assertFailed();
        expect(DB::table('products')->count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
});
