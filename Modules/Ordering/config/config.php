<?php

declare(strict_types=1);

return [
    'name' => 'Ordering',
    // Limits (configuration, not measurements). CatalogServiceProvider's guard reads ordering.max_items.
    'max_items' => (int) env('ORDERING_MAX_ITEMS', 20),
    'max_quantity' => (int) env('ORDERING_MAX_QUANTITY', 10),
    // Default = catalog.price_max_minor (same env fallback): money stays within JSON-safe integers.
    'max_total_minor' => (int) env('ORDERING_MAX_TOTAL_MINOR', env('CATALOG_PRICE_MAX_MINOR', 9007199254740991)),
    // How long a reservation holds stock before Task 6's expiry job releases it.
    'reservation_ttl_seconds' => (int) env('ORDERING_RESERVATION_TTL_SECONDS', 900),
    // SET LOCAL lock_timeout around the idempotency insert (a duplicate waits at most this long).
    'idempotency_lock_timeout_ms' => (int) env('ORDERING_IDEMPOTENCY_LOCK_TIMEOUT_MS', 3000),
    // lock_timeout restored for the stock step; 0 = server default (no timeout).
    'lock_timeout_ms' => (int) env('ORDERING_LOCK_TIMEOUT_MS', 0),
    // Redis (state store) fast path for completed responses; the database stays the truth.
    'idempotency_cache_ttl_seconds' => (int) env('ORDERING_IDEMPOTENCY_CACHE_TTL_SECONDS', 86400),
    'per_page' => (int) env('ORDERING_PER_PAGE', 20),
    'per_page_max' => (int) env('ORDERING_PER_PAGE_MAX', 50),

    // Task 6 payment saga (ADR 0007). Feature tests of POST /orders disable it (OrderingTesting::configure()).
    'saga' => [
        'enabled' => (bool) env('ORDERING_SAGA_ENABLED', true),
        // Exponential retry delays: base * 3^n seconds, capped (configuration, not measurements).
        'backoff_base_seconds' => (int) env('ORDERING_SAGA_BACKOFF_BASE_SECONDS', 5),
        'backoff_cap_seconds' => (int) env('ORDERING_SAGA_BACKOFF_CAP_SECONDS', 120),
        // ThrottlesExceptions around the gateway: this many transient errors in the window opens the
        // shared circuit and payment jobs back off without calling the gateway.
        'gateway_max_exceptions' => (int) env('ORDERING_GATEWAY_MAX_EXCEPTIONS', 20),
        'gateway_decay_seconds' => (int) env('ORDERING_GATEWAY_DECAY_SECONDS', 60),
    ],

    // Reservation expiry sweep (scheduled every minute).
    'expiry' => [
        'batch' => (int) env('ORDERING_EXPIRY_BATCH', 500),
        'max_batches' => (int) env('ORDERING_EXPIRY_MAX_BATCHES', 20),
    ],
];
