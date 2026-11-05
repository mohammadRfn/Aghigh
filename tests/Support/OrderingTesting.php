<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Ordering\Actions\PlaceOrder;
use Modules\Ordering\DTOs\OrderPlacement;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/** Test-only adapter for Inventory and Ordering tests: products, stock rows and orders by id and table only. */
final class OrderingTesting
{
    public static function configure(): void
    {
        CatalogTesting::configure();
        // Task 6: POST /orders now queues the payment saga; with the sync queue it would run inline and
        // change the state these tests assert. Saga tests switch it back on (SagaTesting::configure()).
        config(['inventory.strategy' => 'atomic', 'inventory.naive_delay_ms' => 0, 'ordering.saga.enabled' => false]);
    }

    /** @param array<string, mixed> $attributes */
    public static function product(int $stock = 0, array $attributes = []): string
    {
        $id = (string) Product::factory()->create($attributes)->id;
        if ($stock > 0) {
            self::stock($id, $stock);
        }

        return $id;
    }

    public static function stock(string $productId, int $initial, int $reserved = 0, int $sold = 0): void
    {
        DB::table('stock_items')->insert([
            'product_id' => $productId, 'initial' => $initial, 'available' => $initial - $reserved - $sold,
            'reserved' => $reserved, 'sold' => $sold, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{initial: int, available: int, reserved: int, sold: int} */
    public static function counters(string $productId): array
    {
        $row = DB::table('stock_items')->where('product_id', $productId)->first();

        return [
            'initial' => (int) $row->initial, 'available' => (int) $row->available,
            'reserved' => (int) $row->reserved, 'sold' => (int) $row->sold,
        ];
    }

    public static function balanced(string $productId): bool
    {
        $c = self::counters($productId);

        return min($c) >= 0 && $c['initial'] === $c['available'] + $c['reserved'] + $c['sold'];
    }

    /** @param list<array{product_id: string, quantity: int}> $items */
    public static function place(string $userId, string $key, array $items): OrderPlacement
    {
        return app(PlaceOrder::class)($userId, $key, hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)), $items);
    }

    public static function key(): string
    {
        return 'key-'.bin2hex(random_bytes(10));
    }

    public static function thrown(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $error) {
            return $error;
        }

        throw new AssertionFailedError('Expected an exception, but none was thrown.');
    }
}
