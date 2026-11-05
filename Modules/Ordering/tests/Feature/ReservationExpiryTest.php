<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Ordering\Jobs\ChargePayment;
use Modules\Ordering\Jobs\ExpireReservations;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\ReservationExpiry;
use Tests\Support\OrderingTesting;
use Tests\Support\SagaTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    SagaTesting::configure();
});

it('expires due reserved orders, releases their stock through Inventory and is idempotent', function (): void {
    $due = SagaTesting::order(1000, 2, 10, withSaga: false);
    $paid = SagaTesting::order(1000, 1, 10, withSaga: false);
    ChargePayment::dispatchSync($paid['order']); // paid but not confirmed yet: must keep its stock
    $this->travel((int) config('ordering.reservation_ttl_seconds') + 60)->seconds();
    $fresh = SagaTesting::order(1000, 1, 10, withSaga: false); // not due yet

    ExpireReservations::dispatchSync();

    $status = fn (string $id): string => Order::query()->findOrFail($id)->status->value;
    expect($status($due['order']))->toBe('expired')
        ->and(DB::table('stock_reservations')->where('order_id', $due['order'])->pluck('status')->all())->toBe(['expired'])
        ->and(OrderingTesting::counters($due['product']))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('outbox')->where('aggregate_id', $due['order'])->where('type', 'OrderExpired')->count())->toBe(1)
        ->and($status($paid['order']))->toBe('paid')
        ->and(OrderingTesting::counters($paid['product'])['reserved'])->toBe(1)
        ->and($status($fresh['order']))->toBe('reserved');

    ExpireReservations::dispatchSync();
    expect(app(ReservationExpiry::class)->expireOne($due['order']))->toBe(0)
        ->and(app(ReservationExpiry::class)->sweep(100, 5))->toBe(0)
        ->and(OrderingTesting::counters($due['product']))->toBe(['initial' => 10, 'available' => 10, 'reserved' => 0, 'sold' => 0])
        ->and(DB::table('outbox')->where('aggregate_id', $due['order'])->where('type', 'OrderExpired')->count())->toBe(1);

    // A late ChargePayment for the expired order never charges it.
    ChargePayment::dispatchSync($due['order']);
    expect(DB::table('payments')->where('order_id', $due['order'])->exists())->toBeFalse()
        ->and(Artisan::call('orders:verify-invariants'))->toBe(0);
});

it('sweeps in batches until nothing is due', function (): void {
    $orders = array_map(fn (): array => SagaTesting::order(1000, 1, 5, withSaga: false), range(1, 5));
    $this->travel((int) config('ordering.reservation_ttl_seconds') + 1)->seconds();

    expect(app(ReservationExpiry::class)->sweep(2, 10))->toBe(5)
        ->and(Order::query()->where('status', 'expired')->count())->toBe(5)
        ->and(DB::table('stock_reservations')->where('status', 'active')->count())->toBe(0)
        ->and(count($orders))->toBe(5);
});
