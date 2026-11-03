<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CatalogTesting;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

it('treats malformed or non-object JSON bodies as client errors, never as server errors', function (string $body): void {
    $token = CatalogTesting::token('customer');

    $response = $this->call('POST', '/api/v1/orders', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_IDEMPOTENCY_KEY' => OrderingTesting::key(),
    ], $body);

    expect($response->getStatusCode())->toBeIn([400, 422])
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('idempotency_keys')->count())->toBe(0);
})->with(['{bad json', '{"items":', '[]', '"just a string"', 'null', '']);

it('answers 405 with an Allow header and 404 for unknown sub-routes, as problem json', function (): void {
    $token = CatalogTesting::token('customer');

    $put = $this->putJson('/api/v1/orders', [])->assertStatus(405)->assertHeader('Content-Type', 'application/problem+json');
    expect($put->headers->get('Allow'))->toContain('POST')->toContain('GET');

    $this->deleteJson('/api/v1/orders/'.Str::uuid7())->assertStatus(405);
    $this->withToken($token)->getJson('/api/v1/orders/'.Str::uuid7().'/items')->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
});

it('answers problem json even when the client sends no Accept header', function (): void {
    $this->post('/api/v1/orders')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
});

it('rejects a line or total above the configured maximum through the API', function (): void {
    config(['ordering.max_total_minor' => 1000]);
    $expensive = OrderingTesting::product(10, ['price_minor' => 600]);
    $cheap = [OrderingTesting::product(10, ['price_minor' => 400]), OrderingTesting::product(10, ['price_minor' => 400]), OrderingTesting::product(10, ['price_minor' => 400])];
    $this->withToken(CatalogTesting::token('customer'));

    $this->postJson('/api/v1/orders', ['items' => [['product_id' => $expensive, 'quantity' => 2]]], ['Idempotency-Key' => OrderingTesting::key()])
        ->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
    $this->postJson('/api/v1/orders', ['items' => array_map(fn (string $id): array => ['product_id' => $id, 'quantity' => 1], $cheap)], ['Idempotency-Key' => OrderingTesting::key()])
        ->assertStatus(422)->assertJsonValidationErrors(['items']);

    expect(DB::table('orders')->count())->toBe(0);
});

it('replays from the database over HTTP after the state store was emptied', function (): void {
    $product = OrderingTesting::product(10);
    $key = OrderingTesting::key();
    $payload = ['items' => [['product_id' => $product, 'quantity' => 1]]];
    $this->withToken(CatalogTesting::token('customer'));
    $first = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();

    Cache::store('state')->flush();
    $second = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $key])
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('orders')->count())->toBe(1);
});
