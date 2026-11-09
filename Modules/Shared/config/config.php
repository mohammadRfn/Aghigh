<?php

declare(strict_types=1);

return [
    'name' => 'Shared',

    // Outbox relay (configuration, not measurements).
    'outbox' => [
        'batch' => (int) env('OUTBOX_RELAY_BATCH', 200),
        // A claimed row becomes due again after this lease if the relay dies before marking it.
        'lease_seconds' => (int) env('OUTBOX_RELAY_LEASE_SECONDS', 60),
        // Stop claiming new batches after this long; must stay below the RelayOutbox job timeout (90 s).
        'budget_seconds' => (float) env('OUTBOX_RELAY_BUDGET_SECONDS', 50),
    ],

    // Task 9: metrics (configuration, not measurements; ADR 0010).
    'metrics' => [
        // false selects the null driver so Task 10 can measure the overhead of metrics on/off.
        'enabled' => filter_var(env('METRICS_ENABLED', true), FILTER_VALIDATE_BOOL),
        // redis (default outside tests) | memory (tests only, per process) | null
        'driver' => (string) env('METRICS_DRIVER', 'redis'),
        // Bearer token of GET /internal/metrics; empty = endpoint disabled (404). `make obs-keys` generates it and
        // writes the same value to docker/observability/secrets/metrics_token for Prometheus.
        'token' => (string) env('METRICS_TOKEN', ''),
        // Dedicated Laravel Redis connection (config/database.php): redis-queue db REDIS_METRICS_DB, never `state`.
        'connection' => 'metrics',
        // Library key prefix inside that db (on top of the global phpredis prefix).
        'key_prefix' => 'metrics:',
        // Scrape-time caches so several scrapers cannot stampede Horizon or Reverb.
        'queue_cache_seconds' => (int) env('METRICS_QUEUE_CACHE_SECONDS', 5),
        'reverb' => [
            'cache_seconds' => (int) env('METRICS_REVERB_CACHE_SECONDS', 10),
            'timeout_seconds' => (float) env('METRICS_REVERB_TIMEOUT_SECONDS', 1.0), // capped at 1 s in code
        ],
    ],
];
