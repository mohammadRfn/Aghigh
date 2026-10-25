<?php

declare(strict_types=1);

use Tests\TestCase;

// Feature tests (app-level and per-module) boot the Laravel application.
// Unit tests stay plain PHP; Arch tests never boot the app.
pest()->extend(TestCase::class)
    // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', '../Modules/*/tests/Feature');
