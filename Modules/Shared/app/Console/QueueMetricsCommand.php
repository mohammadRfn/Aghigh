<?php

declare(strict_types=1);

namespace Modules\Shared\Console;

use Illuminate\Console\Command;
use Modules\Shared\Queue\QueueMetrics;

final class QueueMetricsCommand extends Command
{
    protected $signature = 'queue:metrics
        {--json : One JSON document on stdout}
        {--connection= : Queue connection (default: queue.default)}
        {--queue=* : Queues to report (default: orders, broadcasts, notifications, default)}';

    protected $description = 'Queue depth (pending, delayed, reserved), oldest job age, estimated wait and failed jobs';

    public function handle(QueueMetrics $metrics): int
    {
        $connection = $this->option('connection');
        /** @var list<string> $queues */
        $queues = array_values(array_filter((array) $this->option('queue'), 'is_string'));
        $snapshot = $metrics->snapshot($queues, is_string($connection) && $connection !== '' ? $connection : null);

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $show = fn (mixed $value): string => $value === null ? 'n/a' : (string) $value;
        $rows = [];
        foreach ($snapshot['queues'] as $name => $queue) {
            $rows[] = [$name, $show($queue['pending']), $show($queue['delayed']), $show($queue['reserved']), $show($queue['oldest_pending_age_seconds']), $show($queue['estimated_wait_seconds'])];
        }
        $this->table(['queue', 'pending', 'delayed', 'reserved', 'oldest age (s)', 'est. wait (s)'], $rows);
        $this->line(sprintf('connection=%s failed.stored=%s failed.horizon_recent=%s', $snapshot['connection'], $show($snapshot['failed']['stored']), $show($snapshot['failed']['horizon_recent'])));

        return self::SUCCESS;
    }
}
