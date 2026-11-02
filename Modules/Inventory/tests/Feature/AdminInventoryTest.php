<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CatalogTesting;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

it('enforces authentication and the admin role before lookup or validation', function (string $role, int $status): void {
    $unknown = (string) Str::uuid7();
    if ($role !== 'anonymous') {
        $this->withToken(CatalogTesting::token($role));
    }

    $this->getJson('/api/v1/admin/inventory/'.$unknown)->assertStatus($status);
    $this->putJson('/api/v1/admin/inventory/'.$unknown, ['quantity' => 'not-a-number'])->assertStatus($status);
})->with([['anonymous', 401], ['customer', 403]]);

it('uses private no-store for successes and for auth errors', function (): void {
    $url = '/api/v1/admin/inventory/'.OrderingTesting::product();

    $denied = $this->getJson($url)->assertUnauthorized();
    expect($denied->headers->get('Cache-Control'))->toContain('private', 'no-store');

    $ok = $this->withToken(CatalogTesting::token())->getJson($url)->assertOk();
    expect($ok->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

it('shows zero counters for a product that has no stock row yet', function (): void {
    $product = OrderingTesting::product();

    $this->withToken(CatalogTesting::token())->getJson('/api/v1/admin/inventory/'.$product)
        ->assertOk()
        ->assertExactJson(['data' => ['product_id' => $product, 'initial' => 0, 'available' => 0, 'reserved' => 0, 'sold' => 0]]);
});

it('sets the absolute level and reads it back', function (): void {
    $product = OrderingTesting::product();
    $url = '/api/v1/admin/inventory/'.$product;
    $this->withToken(CatalogTesting::token());

    $this->putJson($url, ['quantity' => 25])->assertOk()->assertJsonPath('data.initial', 25)->assertJsonPath('data.available', 25);
    $this->getJson($url)->assertOk()->assertJsonPath('data.available', 25);

    expect(OrderingTesting::counters($product))->toBe(['initial' => 25, 'available' => 25, 'reserved' => 0, 'sold' => 0]);
});

it('allows stocking a draft product', function (): void {
    $product = OrderingTesting::product(0, ['status' => 'draft']);

    $this->withToken(CatalogTesting::token())->putJson('/api/v1/admin/inventory/'.$product, ['quantity' => 5])->assertOk();
});

it('answers unknown products with a not-found problem', function (): void {
    $url = '/api/v1/admin/inventory/'.Str::uuid7();
    $this->withToken(CatalogTesting::token());

    $this->getJson($url)->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
    $this->putJson($url, ['quantity' => 5])->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
});

it('does not route ids that are not UUIDs', function (): void {
    $this->withToken(CatalogTesting::token())->getJson('/api/v1/admin/inventory/not-a-uuid')->assertNotFound();
});

it('validates the quantity', function (): void {
    $product = OrderingTesting::product();
    $this->withToken(CatalogTesting::token());

    foreach ([[], ['quantity' => -1], ['quantity' => '1.5'], ['quantity' => 'abc'], ['quantity' => (int) config('inventory.max_stock') + 1]] as $payload) {
        $this->putJson('/api/v1/admin/inventory/'.$product, $payload)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
    }
});

it('refuses to drop below reserved plus sold with a typed problem', function (): void {
    $product = OrderingTesting::product();
    OrderingTesting::stock($product, 10, reserved: 3, sold: 2);

    $response = $this->withToken(CatalogTesting::token())
        ->putJson('/api/v1/admin/inventory/'.$product, ['quantity' => 4])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('reserved', 3)
        ->assertJsonPath('sold', 2);

    expect($response->json('type'))->toEndWith('/problems/stock-below-allocated')
        ->and(OrderingTesting::counters($product)['initial'])->toBe(10);
});
