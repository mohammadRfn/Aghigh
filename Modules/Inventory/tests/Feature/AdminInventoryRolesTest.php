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

it('keeps stock administration admin-only for support agents too', function (): void {
    $url = '/api/v1/admin/inventory/'.OrderingTesting::product();
    $this->withToken(CatalogTesting::token('support_agent'));

    $this->getJson($url)->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
    $this->putJson($url, ['quantity' => 5])->assertForbidden();

    expect(DB::table('stock_items')->count())->toBe(0);
});
