<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\StockReservations;
use Tests\Support\Race;

require dirname(__DIR__, 4).'/vendor/autoload.php';

// Modes: reserve <strategy> <product,product,...> <quantity each> <naive delay ms> | release <order> | commit <order>
Race::worker($argv, function (array $arguments): array {
    $mode = $arguments[0];
    $rest = array_slice($arguments, 1);

    if ($mode === 'reserve') {
        [$strategy, $products, $quantity, $delay] = $rest;
        config(['inventory.strategy' => $strategy, 'inventory.naive_delay_ms' => (int) $delay]);
        $quantities = [];
        foreach (explode(',', $products) as $product) {
            $quantities[$product] = (int) $quantity;
        }
        $orderId = (string) Str::uuid7();
        DB::transaction(fn () => app(StockReservations::class)->reserve($orderId, $quantities, CarbonImmutable::now()->addMinutes(15)));

        return ['order_id' => $orderId];
    }

    $stock = app(StockReservations::class);

    return ['settled' => $mode === 'release' ? $stock->release($rest[0]) : $stock->commit($rest[0])];
});
