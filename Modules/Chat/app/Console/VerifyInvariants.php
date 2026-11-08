<?php

declare(strict_types=1);

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\InvariantChecker;
use Modules\Chat\Services\SupportQueue;

/**
 * Task 10 after-run check. Non-zero exit on any violation:
 * seq contiguous 1..last_seq (= max(seq)) per conversation; claimed => agent + exactly one open assignment;
 * no open assignment on unclaimed conversations; at most one open conversation per customer;
 * every waiting conversation is in chat:support-queue and every entry is waiting (use --rebuild to sweep first).
 */
final class VerifyInvariants extends Command
{
    protected $signature = 'chat:verify-invariants {--rebuild : Run the queue rebuild before checking the queue} {--skip-queue : Do not compare the Redis queue} {--json : One JSON document on stdout}';

    protected $description = 'Verify chat sequence, assignment, one-open-conversation and support-queue invariants';

    public function handle(InvariantChecker $checker, SupportQueue $queue): int
    {
        if ($this->option('rebuild')) {
            $queue->sweep();
        }
        $violations = $checker->violations(! $this->option('skip-queue'));

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $violations === [], 'violation_count' => count($violations), 'violations' => array_slice($violations, 0, 1000)], JSON_THROW_ON_ERROR));
        } elseif ($violations === []) {
            $this->info('OK: chat invariants hold.');
        } else {
            foreach (array_slice($violations, 0, 50) as $violation) {
                $this->error($violation);
            }
            $this->error(count($violations).' violation(s).');
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
