<?php

declare(strict_types=1);

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Enums\Role;
use Modules\Shared\Support\UnsafeEnvironment;

/**
 * Load-test fixture (Task 10): verified accounts with ONE known password, so k6 can log in without the
 * registration and e-mail flow. Non-production only: the password is documented in the repository.
 *
 *   loadtest-00001@example.test .. loadtest-<count>@example.test   role customer
 *   loadtest-agent-001@example.test .. loadtest-agent-<agents>     role support_agent
 *
 * Idempotent: missing accounts are inserted, existing ones are reset (password, verified, unlocked, role).
 * The password is hashed once and the hash is shared: one Argon2id hash per account would take minutes.
 */
final class LoadtestSeedUsers extends Command
{
    public const DEFAULT_PASSWORD = 'Loadtest-Passphrase-123!';

    public const MAX_CUSTOMERS = 100_000;

    public const MAX_AGENTS = 999;

    protected $signature = 'loadtest:seed-users
        {--count=500 : Customer accounts (loadtest-00001@example.test ...)}
        {--agents=10 : Support agent accounts (loadtest-agent-001@example.test ...)}
        {--password= : Override the known password (at least 12 characters)}
        {--json : One JSON document on stdout}';

    protected $description = 'Create verified load-test accounts with a known password (local/testing/loadtest only).';

    public function handle(): int
    {
        if (! UnsafeEnvironment::allows((string) app()->environment())) {
            $this->error('loadtest:seed-users runs only in local, testing or loadtest.');

            return self::FAILURE;
        }
        $count = (int) $this->option('count');
        $agents = (int) $this->option('agents');
        if ($count < 1 || $count > self::MAX_CUSTOMERS || $agents < 0 || $agents > self::MAX_AGENTS) {
            $this->error('--count must be between 1 and '.self::MAX_CUSTOMERS.' and --agents between 0 and '.self::MAX_AGENTS.'.');

            return self::FAILURE;
        }
        $password = (string) $this->option('password');
        $password = $password === '' ? self::DEFAULT_PASSWORD : $password;
        if (strlen($password) < 12) {
            $this->error('--password must be at least 12 characters.');

            return self::FAILURE;
        }

        /** @var array<string, string> $wanted email => role */
        $wanted = [];
        for ($i = 1; $i <= $count; $i++) {
            $wanted[self::customerEmail($i)] = Role::Customer->value;
        }
        for ($i = 1; $i <= $agents; $i++) {
            $wanted[self::agentEmail($i)] = Role::SupportAgent->value;
        }

        $hash = Hash::make($password);
        $now = now();

        /** @var array<string, true> $existing */
        $existing = [];
        foreach (array_chunk(array_keys($wanted), 500) as $emails) {
            foreach (DB::table('users')->whereIn('email', $emails)->pluck('email') as $email) {
                $existing[(string) $email] = true;
            }
        }

        $rows = [];
        foreach ($wanted as $email => $role) {
            if (isset($existing[$email])) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid7(), 'name' => 'Load test '.$role, 'email' => $email, 'email_verified_at' => $now,
                'password' => $hash, 'role' => $role, 'failed_login_count' => 0, 'locked_until' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        // Accounts left over from an earlier run come back to the same known state (auth-abuse locks some of them).
        $state = ['password' => $hash, 'email_verified_at' => $now, 'failed_login_count' => 0, 'locked_until' => null, 'updated_at' => $now];
        $reset = DB::table('users')->where('email', 'like', 'loadtest-agent-%@example.test')
            ->update($state + ['role' => Role::SupportAgent->value]);
        $reset += DB::table('users')->where('email', 'like', 'loadtest-%@example.test')->where('email', 'not like', 'loadtest-agent-%@example.test')
            ->update($state + ['role' => Role::Customer->value]);

        $summary = ['customers' => $count, 'agents' => $agents, 'created' => count($rows), 'reset' => $reset - count($rows)];
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
        } else {
            $this->info("Load-test accounts ready: {$count} customers, {$agents} agents ({$summary['created']} created, {$summary['reset']} reset).");
        }

        return self::SUCCESS;
    }

    public static function customerEmail(int $number): string
    {
        return sprintf('loadtest-%05d@example.test', $number);
    }

    public static function agentEmail(int $number): string
    {
        return sprintf('loadtest-agent-%03d@example.test', $number);
    }
}
