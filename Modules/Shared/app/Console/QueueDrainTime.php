<?php

declare(strict_types=1);

namespace Modules\Shared\Console;

use Illuminate\Console\Command;
use Modules\Shared\Queue\QueueMetrics;

/**
 * Polls until every queue is empty (pending + delayed + reserved = 0) and prints the elapsed seconds.
 * Task 10 runs it right after a load burst: the number is a measurement of THAT run, nothing more.
 */
final class QueueDrainTime extends Command
{
    protected $signature = 'queue:drain-time
        {--connection= : Queue connection (default: queue.default)}
        {--queue=* : Queues to watch (default: all four)}
        {--interval=0.5 : Seconds between polls}
        {--timeout=900 : Give up after this many seconds (exit code 1)}
        {--json : One JSON document on stdout}';

    protected $description = 'Wait until the queues are drained and print the elapsed seconds';

    public function handle(QueueMetrics $metrics): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        /** @var list<string> $queues */
        $queues = array_values(array_filter((array) $this->option('queue'), 'is_string'));
        $interval = max(0.05, (float) $this->option('interval'));
        $timeout = max(0.1, (float) $this->option('timeout'));

        $started = hrtime(true);
        $polls = 0;
        while (true) {
            $polls++;
            $backlog = $metrics->backlog($queues, $connection);
            $elapsed = round((hrtime(true) - $started) / 1e9, 3);
            if ($backlog === 0) {
                return $this->report(true, $elapsed, $polls, 0);
            }
            if ($elapsed >= $timeout) {
                return $this->report(false, $elapsed, $polls, $backlog);
            }
            usleep((int) ($interval * 1_000_000));
        }
    }

    private function report(bool $drained, float $elapsed, int $polls, ?int $backlog): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['drained' => $drained, 'elapsed_seconds' => $elapsed, 'polls' => $polls, 'backlog' => $backlog], JSON_THROW_ON_ERROR));
        } elseif ($drained) {
            $this->line(number_format($elapsed, 3, '.', ''));
        } else {
            $this->error(sprintf('not drained after %.3f s (backlog: %s)', $elapsed, $backlog === null ? 'unreadable' : (string) $backlog));
        }

        return $drained ? self::SUCCESS : self::FAILURE;
    }
}
