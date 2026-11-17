<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Console\LoadtestSeedUsers;

uses(RefreshDatabase::class);

/*
 | Task 10: loadtest:seed-users. The accounts carry a password that is documented in the repository, so the command
 | must refuse to run anywhere but local, testing and loadtest.
 */

it('creates verified customers and agents with the known password', function (): void {
    $this->artisan('loadtest:seed-users', ['--count' => 3, '--agents' => 2])->assertExitCode(0);

    expect(DB::table('users')->where('email', 'like', 'loadtest-%@example.test')->count())->toBe(5)
        ->and(DB::table('users')->where('email', 'loadtest-00001@example.test')->value('role'))->toBe('customer')
        ->and(DB::table('users')->where('email', 'loadtest-agent-002@example.test')->value('role'))->toBe('support_agent')
        ->and(DB::table('users')->where('email', 'like', 'loadtest-%@example.test')->whereNull('email_verified_at')->count())->toBe(0);

    $hash = (string) DB::table('users')->where('email', 'loadtest-00003@example.test')->value('password');
    expect(Hash::check(LoadtestSeedUsers::DEFAULT_PASSWORD, $hash))->toBeTrue();
});

it('is idempotent and returns locked accounts to the known state', function (): void {
    $this->artisan('loadtest:seed-users', ['--count' => 2, '--agents' => 0])->assertExitCode(0);
    DB::table('users')->where('email', 'loadtest-00001@example.test')
        ->update(['failed_login_count' => 9, 'locked_until' => now()->addHour(), 'email_verified_at' => null]);

    $this->artisan('loadtest:seed-users', ['--count' => 3, '--agents' => 0])->assertExitCode(0);

    $first = DB::table('users')->where('email', 'loadtest-00001@example.test')->first();
    expect(DB::table('users')->where('email', 'like', 'loadtest-%@example.test')->count())->toBe(3)
        ->and((int) $first->failed_login_count)->toBe(0)
        ->and($first->locked_until)->toBeNull()
        ->and($first->email_verified_at)->not->toBeNull();
});

it('refuses to run outside local, testing and loadtest', function (string $environment): void {
    $this->app->detectEnvironment(fn (): string => $environment);

    $this->artisan('loadtest:seed-users', ['--count' => 3])->expectsOutputToContain('runs only in local')->assertExitCode(1);

    expect(DB::table('users')->count())->toBe(0);
})->with(['production', 'staging']);

it('rejects out-of-range options', function (): void {
    $this->artisan('loadtest:seed-users', ['--count' => 0])->assertExitCode(1);
    $this->artisan('loadtest:seed-users', ['--count' => 1, '--password' => 'short'])->assertExitCode(1);

    expect(DB::table('users')->count())->toBe(0);
});
