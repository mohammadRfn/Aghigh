<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Ordering\Actions\CancelOrder;
use Modules\Ordering\Actions\PlaceOrder;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\OrderStateMachine;
use Tests\Support\Race;

require dirname(__DIR__, 4).'/vendor/autoload.php';

// Modes: place <user> <key> <product> <quantity> | cancel <order> | pay <order>
Race::worker($argv, function (array $arguments): array {
    $mode = $arguments[0];
    $rest = array_slice($arguments, 1);

    if ($mode === 'place') {
        [$user, $key, $product, $quantity] = $rest;
        $items = [['product_id' => $product, 'quantity' => (int) $quantity]];
        $placement = app(PlaceOrder::class)($user, $key, hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)), $items);

        return ['status' => $placement->status, 'replayed' => $placement->replayed, 'order_id' => $placement->orderId];
    }

    $order = Order::query()->findOrFail($rest[0]);
    if ($mode === 'cancel') {
        app(CancelOrder::class)($order);
    } else {
        // What Payment will do in Task 6: move to paid and commit the reservations in one transaction.
        DB::transaction(function () use ($order): void {
            app(OrderStateMachine::class)->transition($order, OrderStatus::Paid);
            app(StockReservations::class)->commit($order->id);
        });
    }

    return ['order_id' => $order->id];
});
