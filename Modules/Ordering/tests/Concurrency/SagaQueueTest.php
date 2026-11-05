<?php

declare(strict_types=1);

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\Ordering\Jobs\ChargePayment;
use Modules\Ordering\Jobs\ConfirmOrder;
use Modules\Ordering\Models\Order;
use Modules\Payment\Gateways\FakeGateway;
use Modules\Shared\Queue\QueueMetrics;
use Tests\Support\OrderingTesting;
use Tests\Support\Race;
use Tests\Support\StockInvariants;

/** Environment for child processes: instant deterministic gateway, zero backoff, real Redis queue. */
function sagaChildEnv(): array
{
    return [
        'QUEUE_CONNECTION' => 'redis', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null',
        'ORDERING_SAGA_ENABLED' => 'true', 'ORDERING_SAGA_BACKOFF_BASE_SECONDS' => '0',
        'PAYMENT_FAKE_LATENCY_MIN_MS' => '0', 'PAYMENT_FAKE_LATENCY_MAX_MS' => '5', 'PAYMENT_FAKE_FAILURE_RATE' => '0',
        'PAYMENT_FAKE_TIMEOUT_RATE' => '0', 'PAYMENT_FAKE_TIMEOUT_MS' => '0', 'PAYMENT_FAKE_SEED' => 'saga-queue',
    ];
}

beforeEach(function (): void {
    config([
        'queue.default' => 'redis', 'inventory.strategy' => 'atomic', 'ordering.saga.enabled' => true,
        'ordering.saga.backoff_base_seconds' => 0, 'payment.fake.timeout_ms' => 0, 'payment.fake.latency_max_ms' => 0,
        'payment.fake.failure_rate' => 0.0, 'payment.fake.timeout_rate' => 0.0,
    ]);
    Redis::connection('default')->flushdb(); // queue db of the test run (REDIS_DB=10 in make test-int)
    Cache::store('state')->flush();          // locks, limiter, fake gateway ledger (REDIS_STATE_DB=12)
});

it('drains a batch of sagas with real Redis workers in child processes and every order ends consistent', function (): void {
    $plain = OrderingTesting::product(40, ['price_minor' => 1000]);
    $declined = OrderingTesting::product(10, ['price_minor' => FakeGateway::DECLINE_AMOUNT]);
    $ambiguous = OrderingTesting::product(10, ['price_minor' => FakeGateway::TIMEOUT_AFTER_CAPTURE_AMOUNT]);
    $orders = ['plain' => [], 'declined' => [], 'ambiguous' => []];
    Context::add('request_id', 'saga-queue-test');
    foreach (['plain' => [$plain, 20], 'declined' => [$declined, 5], 'ambiguous' => [$ambiguous, 5]] as $kind => [$product, $count]) {
        foreach (range(1, $count) as $_) {
            $orders[$kind][] = (string) OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => $product, 'quantity' => 1]])->orderId;
        }
    }
    expect(app(QueueMetrics::class)->backlog(['orders'], 'redis'))->toBe(30); // pushed after each commit

    $backlog = null;
    $deadline = microtime(true) + 150;
    do {
        $results = Process::pool(function (Pool $pool): void {
            foreach (range(1, 4) as $worker) {
                $pool->as('w'.$worker)->path(base_path())->env(sagaChildEnv())->timeout(140)
                    ->command(['php', 'artisan', 'queue:work', 'redis', '--queue=orders,notifications,default', '--stop-when-empty', '--sleep=0', '--max-time=120']);
            }
        })->start()->wait();
        foreach ($results as $name => $result) {
            expect($result->successful())->toBeTrue("worker {$name} crashed: ".$result->errorOutput());
        }
        $backlog = app(QueueMetrics::class)->backlog(['orders', 'notifications', 'default'], 'redis');
        if ($backlog !== 0) {
            usleep(500_000); // jobs released by the order lock come back after releaseAfter
        }
    } while ($backlog !== 0 && microtime(true) < $deadline);

    $state = fn (string $id): array => [
        Order::query()->findOrFail($id)->status->value,
        Order::query()->findOrFail($id)->confirmed_at !== null,
        DB::table('payments')->where('order_id', $id)->value('status'),
    ];
    expect($backlog)->toBe(0);
    foreach (array_merge($orders['plain'], $orders['ambiguous']) as $id) {
        expect($state($id))->toBe(['paid', true, 'succeeded']);
    }
    foreach ($orders['declined'] as $id) {
        expect($state($id))->toBe(['cancelled', false, 'failed']);
    }
    expect(DB::table('payments')->count())->toBe(30)
        ->and(DB::table('payments')->whereIn('order_id', $orders['ambiguous'])->pluck('attempts')->map(fn ($n): int => (int) $n)->unique()->values()->all())->toBe([2])
        ->and(OrderingTesting::counters($plain))->toBe(['initial' => 40, 'available' => 20, 'reserved' => 0, 'sold' => 20])
        ->and(OrderingTesting::counters($declined))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0])
        ->and(OrderingTesting::counters($ambiguous))->toBe(['initial' => 10, 'available' => 5, 'reserved' => 0, 'sold' => 5])
        ->and(DB::table('outbox')->where('type', 'OrderConfirmed')->count())->toBe(25)
        ->and(StockInvariants::violations())->toBe([])
        ->and(Artisan::call('orders:verify-invariants'))->toBe(0);
});

it('charges once and commits stock once when the same step runs in parallel and again sequentially', function (): void {
    config(['ordering.saga.enabled' => false]);
    $product = OrderingTesting::product(10, ['price_minor' => 1000]);
    $order = (string) OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => $product, 'quantity' => 2]])->orderId;

    $charges = Race::run(__DIR__.'/saga-worker.php', array_fill(0, 6, ['charge', $order]));
    $confirms = Race::run(__DIR__.'/saga-worker.php', array_fill(0, 6, ['confirm', $order]));
    app()->call([new ChargePayment($order), 'handle']);
    app()->call([new ConfirmOrder($order), 'handle']);

    expect(array_values(array_filter(array_merge($charges, $confirms), fn (array $r): bool => ! $r['ok'])))->toBe([])
        ->and(DB::table('payments')->where('order_id', $order)->count())->toBe(1)
        ->and(DB::table('payments')->where('order_id', $order)->value('status'))->toBe('succeeded')
        ->and(Cache::store('state')->get('payment:fake:charge:'.$order)['approved'] ?? null)->toBeTrue()
        ->and(OrderingTesting::counters($product))->toBe(['initial' => 10, 'available' => 8, 'reserved' => 0, 'sold' => 2])
        ->and(DB::table('outbox')->where('aggregate_id', $order)->where('type', 'OrderConfirmed')->count())->toBe(1)
        ->and(DB::table('outbox')->where('aggregate_id', $order)->where('type', 'OrderPaid')->count())->toBe(1)
        ->and(StockInvariants::violations())->toBe([])
        ->and(Artisan::call('orders:verify-invariants'))->toBe(0);
});
