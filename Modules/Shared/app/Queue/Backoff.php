<?php

declare(strict_types=1);

namespace Modules\Shared\Queue;

use Illuminate\Contracts\Queue\Job;
use Throwable;

/** Retry delays for queued jobs (configuration, not measurements). */
final class Backoff
{
    /**
     * Exponential delays in seconds: base * factor^n, capped. One entry per retry; Laravel reuses the
     * last entry for any further attempt.
     *
     * @return list<int>
     */
    public static function exponential(int $base, int $retries, int $cap = 300, int $factor = 3): array
    {
        $delays = [];
        for ($n = 0; $n < max(1, $retries); $n++) {
            $delays[] = (int) min($cap, max(0, $base) * ($factor ** $n));
        }

        return $delays;
    }

    /**
     * The delay for the current attempt of a queued command, read from the payload exactly like the
     * worker does (the payload froze backoff() at dispatch time).
     */
    public static function delayFor(object $command): int
    {
        $job = property_exists($command, 'job') ? $command->job : null;
        $raw = null;
        if (is_object($job) && method_exists($job, 'backoff')) {
            try {
                $raw = $job->backoff();
            } catch (Throwable) {
                $raw = null; // fake or payload-less jobs (tests)
            }
        }
        if ($raw === null && method_exists($command, 'backoff')) {
            $raw = $command->backoff();
        }
        $delays = is_array($raw) ? array_values($raw) : explode(',', (string) ($raw ?? '0'));
        $attempts = $job instanceof Job ? max(1, $job->attempts()) : 1;

        return max(0, (int) ($delays[$attempts - 1] ?? end($delays)));
    }
}
