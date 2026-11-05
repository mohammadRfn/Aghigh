<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Ordering\Jobs\ChargePayment;
use Modules\Ordering\Jobs\CompensateOrder;
use Modules\Ordering\Jobs\ConfirmOrder;
use Modules\Ordering\Jobs\ExpireReservations;
use Modules\Ordering\Jobs\NotifyOrderConfirmed;
use Modules\Ordering\Jobs\OrderSagaJob;
use Modules\Ordering\Jobs\RefundPayment;
use Modules\Ordering\Jobs\SendOrderConfirmation;
use Modules\Ordering\Mail\OrderConfirmedMail;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Saga\CompensateOnChainFailure;
use Modules\Payment\Gateways\FakeGateway;
use Modules\Shared\Jobs\RelayOutbox;
use Modules\Shared\Queue\Middleware\FailWithoutRetry;
use Modules\Shared\Queue\Middleware\ThrottlesExceptionsWithBackoff;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Tests\Support\OrderingTesting;
use Tests\Support\SagaTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    SagaTesting::configure();
});

function sagaState(string $orderId): array
{
    $order = Order::query()->findOrFail($orderId);

    return [
        'status' => $order->status->value,
        'confirmed' => $order->confirmed_at !== null,
        'notified' => $order->notified_at !== null,
        'payment' => DB::table('payments')->where('order_id', $orderId)->value('status'),
        'reservations' => DB::table('stock_reservations')->where('order_id', $orderId)->pluck('status')->unique()->values()->all(),
    ];
}

function invariantsHold(): bool
{
    return Artisan::call('orders:verify-invariants') === 0;
}

it('queues ChargePayment -> ConfirmOrder -> Notify on the orders queue with a catch callback', function (): void {
    Bus::fake();
    $placed = SagaTesting::order();

    Bus::assertChained([ChargePayment::class, ConfirmOrder::class, NotifyOrderConfirmed::class]);
    Bus::assertDispatched(ChargePayment::class, fn (ChargePayment $job): bool => $job->orderId === $placed['order']
        && $job->queue === 'orders' && $job->afterCommit === true
        && count($job->chainCatchCallbacks) === 1 && $job->chainCatchCallbacks[0] instanceof CompensateOnChainFailure);
});

it('queues nothing when the order transaction rolls back', function (): void {
    Bus::fake();
    $product = OrderingTesting::product(1, ['price_minor' => 1000]);

    $error = OrderingTesting::thrown(fn () => OrderingTesting::place((string) Str::uuid7(), OrderingTesting::key(), [['product_id' => $product, 'quantity' => 2]]));

    expect($error->getMessage())->not->toBe('');
    Bus::assertNothingDispatched();
});

it('pays, confirms and notifies a placed order end to end', function (): void {
    Mail::fake();
    $placed = SagaTesting::order(1000, 2, 10);

    expect(sagaState($placed['order']))->toBe(['status' => 'paid', 'confirmed' => true, 'notified' => true, 'payment' => 'succeeded', 'reservations' => ['committed']])
        ->and(OrderingTesting::counters($placed['product']))->toBe(['initial' => 10, 'available' => 8, 'reserved' => 0, 'sold' => 2])
        ->and(DB::table('outbox')->where('aggregate_id', $placed['order'])->orderBy('id')->pluck('type')->all())->toBe(['OrderReserved', 'OrderPaid', 'OrderConfirmed'])
        ->and(invariantsHold())->toBeTrue();
    Mail::assertSent(OrderConfirmedMail::class, 1);
});

it('compensates a declined payment: cancelled, stock released, payment failed, nothing to refund', function (): void {
    $placed = SagaTesting::order(FakeGateway::DECLINE_AMOUNT, 1, 5);

    expect(sagaState($placed['order']))->toBe(['status' => 'cancelled', 'confirmed' => false, 'notified' => false, 'payment' => 'failed', 'reservations' => ['released']])
        ->and(OrderingTesting::counters($placed['product']))->toBe(['initial' => 5, 'available' => 5, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('payments')->where('order_id', $placed['order'])->value('refund_reference'))->toBeNull()
        ->and(invariantsHold())->toBeTrue();
});

it('compensates and refunds when confirmation fails after the charge', function (): void {
    $placed = SagaTesting::order(1000, 1, 5, withSaga: false);
    ChargePayment::dispatchSync($placed['order']);
    $inner = app(StockReservations::class);
    app()->instance(StockReservations::class, new class($inner) implements StockReservations
    {
        public function __construct(private readonly StockReservations $inner) {}

        public function reserve(string $orderId, array $quantities, DateTimeInterface $expiresAt): void
        {
            $this->inner->reserve($orderId, $quantities, $expiresAt);
        }

        public function release(string $orderId): int
        {
            return $this->inner->release($orderId);
        }

        public function commit(string $orderId): int
        {
            throw new RuntimeException('injected: stock commit failed');
        }

        public function expire(string $orderId): int
        {
            return $this->inner->expire($orderId);
        }

        public function releaseExpired(int $limit): array
        {
            return $this->inner->releaseExpired($limit);
        }
    });

    expect(fn () => ConfirmOrder::dispatchSync($placed['order']))->toThrow(RuntimeException::class, 'injected: stock commit failed');

    expect(sagaState($placed['order']))->toBe(['status' => 'cancelled', 'confirmed' => false, 'notified' => false, 'payment' => 'refunded', 'reservations' => ['released']])
        ->and(OrderingTesting::counters($placed['product']))->toBe(['initial' => 5, 'available' => 5, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('outbox')->where('aggregate_id', $placed['order'])->where('type', 'OrderConfirmed')->count())->toBe(0)
        ->and(invariantsHold())->toBeTrue();
});

it('keeps a confirmed order when the notification step fails (past the pivot)', function (): void {
    $placed = SagaTesting::order(1000, 1, 5, withSaga: false);
    ChargePayment::dispatchSync($placed['order']);
    ConfirmOrder::dispatchSync($placed['order']);
    app()->instance(UserDirectory::class, new class implements UserDirectory
    {
        public function emailOf(string $userId): ?string
        {
            throw new RuntimeException('injected: directory down');
        }
    });

    expect(fn () => NotifyOrderConfirmed::dispatchSync($placed['order']))->toThrow(RuntimeException::class, 'injected: directory down');
    (new NotifyOrderConfirmed($placed['order']))->failed(new RuntimeException('again'));

    expect(sagaState($placed['order']))->toBe(['status' => 'paid', 'confirmed' => true, 'notified' => false, 'payment' => 'succeeded', 'reservations' => ['committed']])
        ->and(OrderingTesting::counters($placed['product']))->toBe(['initial' => 5, 'available' => 4, 'reserved' => 0, 'sold' => 1])
        ->and(invariantsHold())->toBeTrue();
});

it('processes duplicate deliveries of every step exactly once', function (): void {
    Mail::fake();
    $placed = SagaTesting::order(1000, 3, 10, withSaga: false);

    foreach ([1, 2] as $round) {
        ChargePayment::dispatchSync($placed['order']);
        ConfirmOrder::dispatchSync($placed['order']);
        NotifyOrderConfirmed::dispatchSync($placed['order']);
        CompensateOrder::dispatchSync($placed['order'], 'late-duplicate');
    }

    expect(DB::table('payments')->where('order_id', $placed['order'])->count())->toBe(1)
        ->and((int) DB::table('payments')->where('order_id', $placed['order'])->value('attempts'))->toBe(1)
        ->and(OrderingTesting::counters($placed['product']))->toBe(['initial' => 10, 'available' => 7, 'reserved' => 0, 'sold' => 3])
        ->and(DB::table('outbox')->where('aggregate_id', $placed['order'])->where('type', 'OrderConfirmed')->count())->toBe(1)
        ->and(sagaState($placed['order']))->toBe(['status' => 'paid', 'confirmed' => true, 'notified' => true, 'payment' => 'succeeded', 'reservations' => ['committed']])
        ->and(invariantsHold())->toBeTrue();
    Mail::assertSent(OrderConfirmedMail::class, 1);
});

it('releases a step while another step of the same order holds the order lock', function (): void {
    $placed = SagaTesting::order(1000, 1, 5, withSaga: false);
    $other = SagaTesting::order(1000, 1, 5, withSaga: false);
    $key = OrderSagaJob::orderLock($placed['order'], 30)->getLockKey(new ChargePayment($placed['order']));
    $lock = Cache::store('state')->lock($key, 60);
    expect($lock->get())->toBeTrue()
        ->and($key)->toBe(OrderSagaJob::orderLock($placed['order'], 30)->getLockKey(new ConfirmOrder($placed['order']))); // shared by every step

    ChargePayment::dispatchSync($placed['order']); // lock busy: released, not run
    ChargePayment::dispatchSync($other['order']);  // another order is not blocked

    expect(sagaState($placed['order'])['status'])->toBe('reserved')
        ->and(DB::table('payments')->where('order_id', $placed['order'])->exists())->toBeFalse()
        ->and(sagaState($other['order'])['status'])->toBe('paid');

    $lock->release();
    ChargePayment::dispatchSync($placed['order']);
    expect(sagaState($placed['order'])['status'])->toBe('paid');
});

it('declares tries, exponential backoff, maxExceptions, timeouts, locks and failed handlers', function (): void {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $id = (string) Str::uuid7();
    $read = fn (object $middleware, string $property): mixed => (new ReflectionProperty(WithoutOverlapping::class, $property))->getValue($middleware);

    foreach ([new ChargePayment($id), new ConfirmOrder($id), new NotifyOrderConfirmed($id), new CompensateOrder($id), new RefundPayment($id)] as $job) {
        $backoff = $job->backoff();
        $middleware = $job->middleware();
        $lock = collect($middleware)->first(fn (object $m): bool => $m instanceof WithoutOverlappingOnState);

        expect($job->tries)->toBeGreaterThan(1)
            ->and($job->maxExceptions)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($job->tries)
            ->and($job->timeout)->toBeLessThan($retryAfter)
            ->and($job->queue)->toBe('orders')
            ->and($job->afterCommit)->toBeTrue()
            ->and($backoff)->toHaveCount($job->tries - 1)
            ->and($middleware[0])->toBeInstanceOf(FailWithoutRetry::class)
            ->and($lock)->toBeInstanceOf(WithoutOverlappingOnState::class)
            ->and($lock->getLockKey($job))->toContain($id)
            ->and($read($lock, 'expiresAfter'))->toBeGreaterThan($job->timeout)
            ->and($read($lock, 'releaseAfter'))->toBe(5)
            ->and(method_exists($job, 'failed'))->toBeTrue()
            ->and(serialize($job))->not->toContain('Models\\');
    }

    config(['ordering.saga.backoff_base_seconds' => 5]);
    expect((new ChargePayment($id))->backoff())->toBe([5, 15, 45, 120, 120, 120, 120, 120, 120]);

    foreach ([new ChargePayment($id), new RefundPayment($id)] as $job) {
        expect(collect($job->middleware())->contains(fn (object $m): bool => $m instanceof ThrottlesExceptionsWithBackoff))->toBeTrue();
    }
    foreach ([new CompensateOrder($id), new RefundPayment($id), new SendOrderConfirmation($id), new ExpireReservations, new RelayOutbox] as $job) {
        expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->uniqueVia()->getStore())->toBe(Cache::store('state')->getStore());
    }
});

it('hands the order to compensation from failed() and the chain catch callback, collapsed to one job', function (): void {
    Queue::fake();
    $id = (string) Str::uuid7();

    (new ChargePayment($id))->failed(new RuntimeException('step failed'));
    (new CompensateOnChainFailure($id))(new RuntimeException('chain failed'));
    (new CompensateOrder($id))->failed(new RuntimeException('compensation failed'));
    (new RefundPayment($id))->failed(new RuntimeException('refund failed'));

    Queue::assertPushed(CompensateOrder::class, 1); // ShouldBeUnique: two triggers, one compensation queued
    Queue::assertPushed(CompensateOrder::class, fn (CompensateOrder $job): bool => $job->orderId === $id && $job->queue === 'orders');
});
