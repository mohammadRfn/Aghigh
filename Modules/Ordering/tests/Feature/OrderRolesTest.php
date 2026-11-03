<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogTesting;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

it('lets every authenticated role order, list, show and cancel its own orders', function (string $role): void {
    $product = OrderingTesting::product(20);
    $this->withToken(CatalogTesting::token($role));

    $id = $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => OrderingTesting::key()])
        ->assertCreated()->json('data.id');

    $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
})->with(['customer', 'support_agent', 'admin']);

it('hides a customers orders from every other role, administrators included', function (string $role): void {
    $product = OrderingTesting::product(10);
    $id = $this->withToken(CatalogTesting::token('customer'))
        ->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => OrderingTesting::key()])
        ->assertCreated()->json('data.id');

    $this->app->forgetScopedInstances(); // CurrentUser is scoped: drop the previous request's user
    $this->withToken(CatalogTesting::token($role));

    $this->getJson('/api/v1/orders/'.$id)->assertNotFound();
    $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertNotFound();
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('reserved');
})->with(['support_agent', 'admin']);
