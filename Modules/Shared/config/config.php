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
];
