<?php

declare(strict_types=1);

return [
    'name' => 'Inventory',
    // naive | atomic | locking (ADR 0005). naive is refused outside local/testing/loadtest.
    'strategy' => env('STOCK_STRATEGY', 'atomic'),
    // Deliberate pause between the naive read and write (test aid, never a latency figure).
    'naive_delay_ms' => (int) env('STOCK_NAIVE_DELAY_MS', 0),
    // Upper bound for an absolute stock level (admin PUT and inventory:set-stock).
    'max_stock' => (int) env('INVENTORY_MAX_STOCK', 1_000_000_000),
];
