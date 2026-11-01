<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Models\Product;
use Tests\Support\CatalogTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CatalogTesting::configure();
});

it('serves real database rows when either public cache dependency fails', function (string $failed): void {
    $product = Product::factory()->create();
    $state = Cache::store('state');
    $payload = Cache::store('cache');
    Cache::shouldReceive('store')->with($failed)->andThrow(new RuntimeException('simulated store failure'));
    Cache::shouldReceive('store')->with($failed === 'state' ? 'cache' : 'state')->andReturn($failed === 'state' ? $payload : $state);
    Log::shouldReceive('channel')->with('app')->andReturnSelf();
    Log::shouldReceive('warning')->atLeast()->once();
    $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.id', $product->id)->assertHeaderMissing('Set-Cookie');
})->with(['cache', 'state']);

it('audits entity and actor identifiers with only actually changed field names', function (): void {
    $product = Product::factory()->create();
    $token = CatalogTesting::token();
    Log::shouldReceive('channel')->with('audit')->andReturnSelf();
    Log::shouldReceive('info')->once()->with('catalog.write', Mockery::on(function (array $context) use ($product): bool {
        return $context['entity_id'] === $product->id
            && is_string($context['actor_id'])
            && $context['entity'] === 'product'
            && $context['changed_fields'] === ['name']
            && ! array_key_exists('body', $context);
    }));
    $this->withToken($token)->patchJson('/api/v1/admin/products/'.$product->id, ['name' => 'Actually changed', 'sku' => $product->sku])->assertOk();
});

it('selects the earliest image position and a deterministic UUID tie', function (): void {
    $product = Product::factory()->create();
    $late = $product->images()->create(['path' => 'late.jpg', 'width' => 50, 'height' => 50, 'position' => 5, 'alt' => 'Late']);
    $first = $product->images()->create(['path' => 'first.jpg', 'width' => 50, 'height' => 50, 'position' => 0, 'alt' => 'First']);
    $product->images()->create(['path' => 'tie.jpg', 'width' => 50, 'height' => 50, 'position' => 0, 'alt' => 'Tie']);
    $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.primary_image.id', $first->id);
    expect($late->id)->not->toBe($first->id);
});
