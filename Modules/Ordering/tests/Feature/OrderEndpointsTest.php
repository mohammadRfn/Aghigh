<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CatalogTesting;
use Tests\Support\OrderingTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    OrderingTesting::configure();
});

it('requires authentication on every endpoint and keeps the answers private', function (): void {
    $id = (string) Str::uuid7();

    foreach ([
        fn () => $this->postJson('/api/v1/orders', []),
        fn () => $this->getJson('/api/v1/orders'),
        fn () => $this->getJson('/api/v1/orders/'.$id),
        fn () => $this->postJson('/api/v1/orders/'.$id.'/cancel'),
    ] as $request) {
        $response = $request()->assertUnauthorized();
        expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    }
});

it('requires a valid Idempotency-Key before looking at the body', function (): void {
    $this->withToken(CatalogTesting::token('customer'));

    foreach ([null, 'short', str_repeat('a', 65), 'has spaces in the key!!'] as $key) {
        $response = $this->postJson('/api/v1/orders', ['items' => 'garbage'], $key === null ? [] : ['Idempotency-Key' => $key])->assertStatus(422);
        expect($response->json('type'))->toEndWith('/problems/idempotency-key-invalid');
    }
});

it('places an order priced on the server and reserves stock', function (): void {
    $a = OrderingTesting::product(10, ['price_minor' => 1000]);
    $b = OrderingTesting::product(5, ['price_minor' => 250]);
    $this->withToken(CatalogTesting::token('customer'));

    $response = $this->postJson('/api/v1/orders', [
        'items' => [['product_id' => $a, 'quantity' => 3, 'unit_price_minor' => 1], ['product_id' => $b, 'quantity' => 2]],
        'total_minor' => 1,
    ], ['Idempotency-Key' => OrderingTesting::key()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'reserved')
        ->assertJsonPath('data.total_minor', 3500)
        ->assertJsonPath('data.version', 2)
        ->assertJsonCount(2, 'data.items');

    $id = $response->json('data.id');
    expect($response->headers->get('Location'))->toBe('/api/v1/orders/'.$id)
        ->and($response->headers->get('Idempotency-Replayed'))->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and(collect($response->json('data.items'))->pluck('unit_price_minor', 'product_id')->all())->toEqual([$a => 1000, $b => 250])
        ->and(OrderingTesting::counters($a))->toBe(['initial' => 10, 'available' => 7, 'reserved' => 3, 'sold' => 0])
        ->and(OrderingTesting::counters($b))->toBe(['initial' => 5, 'available' => 3, 'reserved' => 2, 'sold' => 0]);
});

it('replays the stored response for the same key even when items are reordered', function (): void {
    $a = OrderingTesting::product(10);
    $b = OrderingTesting::product(10);
    $key = OrderingTesting::key();
    $items = [['product_id' => $a, 'quantity' => 1], ['product_id' => $b, 'quantity' => 2]];
    $this->withToken(CatalogTesting::token('customer'));

    $first = $this->postJson('/api/v1/orders', ['items' => $items], ['Idempotency-Key' => $key])->assertCreated();
    $second = $this->postJson('/api/v1/orders', ['items' => array_reverse($items)], ['Idempotency-Key' => $key])
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(OrderingTesting::counters($a)['reserved'])->toBe(1);
});

it('refuses the same key with a different payload', function (): void {
    $product = OrderingTesting::product(10);
    $key = OrderingTesting::key();
    $this->withToken(CatalogTesting::token('customer'));
    $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => $key])->assertCreated();

    $response = $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 2]]], ['Idempotency-Key' => $key])->assertStatus(422);

    expect($response->json('type'))->toEndWith('/problems/idempotency-key-reuse')
        ->and(DB::table('orders')->count())->toBe(1);
});

it('rejects malformed bodies', function (string $case, string $errorKey): void {
    $product = OrderingTesting::product(50);
    $payload = match ($case) {
        'no items' => [],
        'empty items' => ['items' => []],
        'items not a list' => ['items' => 'x'],
        'too many items' => ['items' => array_map(fn (): array => ['product_id' => (string) Str::uuid7(), 'quantity' => 1], range(1, (int) config('ordering.max_items') + 1))],
        'non uuid product' => ['items' => [['product_id' => 'nope', 'quantity' => 1]]],
        'duplicate product, case-insensitive' => ['items' => [['product_id' => $product, 'quantity' => 1], ['product_id' => strtoupper($product), 'quantity' => 1]]],
        'missing quantity' => ['items' => [['product_id' => $product]]],
        'zero quantity' => ['items' => [['product_id' => $product, 'quantity' => 0]]],
        'quantity above the maximum' => ['items' => [['product_id' => $product, 'quantity' => (int) config('ordering.max_quantity') + 1]]],
        'fractional quantity' => ['items' => [['product_id' => $product, 'quantity' => 1.5]]],
    };

    $this->withToken(CatalogTesting::token('customer'))
        ->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => OrderingTesting::key()])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$errorKey]);

    expect(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('idempotency_keys')->count())->toBe(0);
})->with([
    ['no items', 'items'],
    ['empty items', 'items'],
    ['items not a list', 'items'],
    ['too many items', 'items'],
    ['non uuid product', 'items.0.product_id'],
    ['duplicate product, case-insensitive', 'items.0.product_id'],
    ['missing quantity', 'items.0.quantity'],
    ['zero quantity', 'items.0.quantity'],
    ['quantity above the maximum', 'items.0.quantity'],
    ['fractional quantity', 'items.0.quantity'],
]);

it('rejects unknown and unpublished products', function (): void {
    $draft = OrderingTesting::product(5, ['status' => 'draft']);

    $this->withToken(CatalogTesting::token('customer'))
        ->postJson('/api/v1/orders', ['items' => [
            ['product_id' => (string) Str::uuid7(), 'quantity' => 1],
            ['product_id' => $draft, 'quantity' => 1],
        ]], ['Idempotency-Key' => OrderingTesting::key()])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.product_id', 'items.1.product_id']);
});

it('answers 409 out-of-stock and stores nothing', function (): void {
    $product = OrderingTesting::product(2);

    $response = $this->withToken(CatalogTesting::token('customer'))
        ->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 3]]], ['Idempotency-Key' => OrderingTesting::key()])
        ->assertStatus(409)
        ->assertJsonPath('product_ids', [$product]);

    expect($response->json('type'))->toEndWith('/problems/out-of-stock')
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('idempotency_keys')->count())->toBe(0)
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 2, 'available' => 2, 'reserved' => 0, 'sold' => 0]);
});

it('shows only the callers own orders and hides everyone elses behind a 404', function (): void {
    $product = OrderingTesting::product(10);
    $owner = CatalogTesting::token('customer');
    $stranger = CatalogTesting::token('customer');
    $id = $this->withToken($owner)
        ->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => OrderingTesting::key()])
        ->json('data.id');

    $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'reserved');

    $this->app->forgetScopedInstances(); // CurrentUser is scoped: drop the previous request's user
    $this->withToken($stranger);
    $foreign = $this->getJson('/api/v1/orders/'.$id)->assertNotFound();
    $unknown = $this->getJson('/api/v1/orders/'.Str::uuid7())->assertNotFound();
    expect($foreign->json('type'))->toBe($unknown->json('type'))
        ->and($foreign->json('detail'))->toBe($unknown->json('detail'));
    $this->getJson('/api/v1/orders/not-a-uuid')->assertNotFound();
});

it('keyset-paginates own orders newest first without duplicates or gaps', function (): void {
    $product = OrderingTesting::product(50);
    $mine = CatalogTesting::token('customer');
    $other = CatalogTesting::token('customer');
    $this->withToken($mine);
    $created = [];
    foreach (range(1, 5) as $ignored) {
        $created[] = $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => OrderingTesting::key()])
            ->assertCreated()->json('data.id');
    }
    $this->app->forgetScopedInstances(); // CurrentUser is scoped: drop the previous request's user
    $this->withToken($other)->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => OrderingTesting::key()])->assertCreated();
    $this->app->forgetScopedInstances(); // CurrentUser is scoped: drop the previous request's user
    $this->withToken($mine);

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $query = ['per_page' => 2] + ($cursor === null ? [] : ['cursor' => $cursor]);
        $page = $this->getJson('/api/v1/orders?'.http_build_query($query))->assertOk()->assertJsonPath('meta.per_page', 2);
        $seen = [...$seen, ...array_column($page->json('data'), 'id')];
        $cursor = $page->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    $descending = $seen;
    rsort($descending);
    expect($pages)->toBe(3)
        ->and($seen)->toHaveCount(5)
        ->and($seen)->toBe($descending)
        ->and(array_diff($created, $seen))->toBe([]);
});

it('filters by status and rejects bad query parameters', function (): void {
    $product = OrderingTesting::product(20);
    $this->withToken(CatalogTesting::token('customer'));
    $ids = [];
    foreach (range(1, 2) as $ignored) {
        $ids[] = $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => OrderingTesting::key()])->json('data.id');
    }
    $this->postJson('/api/v1/orders/'.$ids[0].'/cancel')->assertOk();

    $this->getJson('/api/v1/orders?status=cancelled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[0]);
    $this->getJson('/api/v1/orders?status=reserved')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[1]);

    $this->getJson('/api/v1/orders?status=bogus')->assertStatus(422)->assertJsonValidationErrors(['status']);
    $this->getJson('/api/v1/orders?per_page='.((int) config('ordering.per_page_max') + 1))->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    $this->getJson('/api/v1/orders?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    $this->getJson('/api/v1/orders?cursor=not-a-cursor')->assertStatus(422)->assertJsonValidationErrors(['cursor']);
    $this->getJson('/api/v1/orders?cursor='.rtrim(strtr(base64_encode('not-a-uuid'), '+/', '-_'), '='))->assertStatus(422)->assertJsonValidationErrors(['cursor']);
});

it('bounds the number of queries independently of how many orders are listed', function (): void {
    $product = OrderingTesting::product(50);
    $this->withToken(CatalogTesting::token('customer'));
    $place = fn () => $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => OrderingTesting::key()])->assertCreated();
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/orders')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $place();
    $withOne = $queries();
    foreach (range(1, 4) as $ignored) {
        $place();
    }

    expect($queries())->toBe($withOne);
});

it('cancels an own order, releases its stock and refuses a second cancel', function (): void {
    $product = OrderingTesting::product(10);
    $this->withToken(CatalogTesting::token('customer'));
    $id = $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 4]]], ['Idempotency-Key' => OrderingTesting::key()])->json('data.id');

    $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.version', 3);
    expect(OrderingTesting::counters($product))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0]);

    $again = $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertStatus(409);
    expect($again->json('type'))->toEndWith('/problems/invalid-order-transition')
        ->and(OrderingTesting::counters($product)['available'])->toBe(10);
});

it('does not let anyone cancel someone elses order or a paid one', function (): void {
    $product = OrderingTesting::product(10);
    $owner = CatalogTesting::token('customer');
    $id = $this->withToken($owner)
        ->postJson('/api/v1/orders', ['items' => [['product_id' => $product, 'quantity' => 4]]], ['Idempotency-Key' => OrderingTesting::key()])
        ->json('data.id');

    $this->app->forgetScopedInstances(); // CurrentUser is scoped: drop the previous request's user
    $this->withToken(CatalogTesting::token('customer'))->postJson('/api/v1/orders/'.$id.'/cancel')->assertNotFound();
    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('reserved');

    DB::table('orders')->where('id', $id)->update(['status' => 'paid']);
    $this->app->forgetScopedInstances(); // CurrentUser is scoped: drop the previous request's user
    $paid = $this->withToken($owner)->postJson('/api/v1/orders/'.$id.'/cancel')->assertStatus(409);
    expect($paid->json('type'))->toEndWith('/problems/invalid-order-transition')
        ->and(OrderingTesting::counters($product)['reserved'])->toBe(4);
});
