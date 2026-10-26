<?php

declare(strict_types=1);

use Tests\ConcurrencyTestCase;
use Tests\TestCase;

// Feature tests (app-level and per-module) boot the Laravel application.
// Unit tests stay plain PHP; Arch tests never boot the app.
pest()->extend(TestCase::class)
    // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', '../Modules/*/tests/Feature');

// Real Postgres + Redis; skipped unless the database name ends with "_test" (make test-int).
pest()->extend(ConcurrencyTestCase::class)
    ->in('Concurrency', '../Modules/*/tests/Concurrency');
