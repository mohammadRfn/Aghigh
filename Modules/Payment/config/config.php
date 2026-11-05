<?php

declare(strict_types=1);

// Configuration, not measurements.
return [
    'name' => 'Payment',

    // Only the simulated provider exists in this showcase.
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    'fake' => [
        'latency_min_ms' => (int) env('PAYMENT_FAKE_LATENCY_MIN_MS', 50),
        'latency_max_ms' => (int) env('PAYMENT_FAKE_LATENCY_MAX_MS', 250),
        'failure_rate' => (float) env('PAYMENT_FAKE_FAILURE_RATE', 0.02),
        'timeout_rate' => (float) env('PAYMENT_FAKE_TIMEOUT_RATE', 0.01),
        // How long a simulated timeout blocks the worker; must stay well below the ChargePayment timeout (30 s).
        'timeout_ms' => (int) env('PAYMENT_FAKE_TIMEOUT_MS', 3000),
        // Same seed = same decisions for the same orders (reproducible load tests). Empty = random.
        'seed' => env('PAYMENT_FAKE_SEED'),
        'ledger_store' => env('PAYMENT_FAKE_LEDGER_STORE', 'state'),
        'ledger_ttl_seconds' => (int) env('PAYMENT_FAKE_LEDGER_TTL_SECONDS', 604800),
    ],

    'webhook' => [
        'secret' => (string) env('PAYMENT_WEBHOOK_SECRET', ''),
        // Rotation: the old secret stays accepted until every sender uses the new one.
        'previous_secret' => (string) env('PAYMENT_WEBHOOK_SECRET_PREVIOUS', ''),
        'tolerance_seconds' => (int) env('PAYMENT_WEBHOOK_TOLERANCE_SECONDS', 300),
        'max_bytes' => (int) env('PAYMENT_WEBHOOK_MAX_BYTES', 65536),
        // Replay markers need SETNX on a NON-evicting store.
        'replay_store' => env('PAYMENT_WEBHOOK_REPLAY_STORE', 'state'),
    ],
];
