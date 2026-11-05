<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use Modules\Identity\Contracts\UserDirectory;

/** Feature-test setup for the Task 6 saga: sync queue, instant deterministic fake gateway, array state store. */
final class SagaTesting
{
    public static function configure(): void
    {
        OrderingTesting::configure(); // array state store, atomic stock strategy, saga off
        config([
            'ordering.saga.enabled' => true,
            'ordering.saga.backoff_base_seconds' => 0,
            'queue.locks.store' => 'state',
            'payment.gateway' => 'fake',
            'payment.fake' => [
                'latency_min_ms' => 0, 'latency_max_ms' => 0, 'failure_rate' => 0.0, 'timeout_rate' => 0.0,
                'timeout_ms' => 0, 'seed' => 'saga-tests', 'ledger_store' => 'state', 'ledger_ttl_seconds' => 3600,
            ],
        ]);
        app()->instance(UserDirectory::class, new class implements UserDirectory
        {
            public function emailOf(string $userId): ?string
            {
                return 'buyer-'.substr($userId, -8).'@example.test';
            }
        });
    }

    /**
     * Place an order for one product; the saga runs inline (sync queue) unless $withSaga is false.
     *
     * @return array{order: string, product: string, user: string}
     */
    public static function order(int $priceMinor = 1000, int $quantity = 1, int $stock = 10, bool $withSaga = true): array
    {
        $previous = config('ordering.saga.enabled');
        config(['ordering.saga.enabled' => $withSaga]);
        try {
            $product = OrderingTesting::product($stock, ['price_minor' => $priceMinor]);
            $user = (string) Str::uuid7();
            $placement = OrderingTesting::place($user, OrderingTesting::key(), [['product_id' => $product, 'quantity' => $quantity]]);
        } finally {
            config(['ordering.saga.enabled' => $previous]);
        }

        return ['order' => (string) $placement->orderId, 'product' => $product, 'user' => $user];
    }
}
