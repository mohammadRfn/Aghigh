<?php

declare(strict_types=1);

namespace Tests;

/**
 * Base for tests that need real Postgres + Redis (races, locks, Lua).
 * Refuses to run unless the database name ends with "_test", because it runs migrate:fresh.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");

        if ($default !== 'pgsql' || ! str_ends_with($database, '_test')) {
            $this->markTestSkipped('Needs a pgsql database whose name ends with _test (see make test-int).');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }
}
