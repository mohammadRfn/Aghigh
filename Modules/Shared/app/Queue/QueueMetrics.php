<?php

declare(strict_types=1);

namespace Modules\Shared\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\QueueManager;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/**
 * Read-only queue gauges: source for Task 9's scraper, queue:metrics and queue:drain-time.
 * - pending / delayed / reserved: straight from the driver (RedisQueue::pendingSize() etc.).
 * - oldest_pending_age_seconds: now - createdAt of the head job (exact, from the payload).
 * - estimated_wait_seconds: Horizon's WaitTimeCalculator (queue size x average runtime / processes),
 *   an ESTIMATE that needs Horizon's metrics snapshots; null when Horizon has no data.
 * - failed.stored: rows in failed_jobs; failed.horizon_recent: Horizon's recently-failed counter.
 * A broken source yields null, never an exception: a scrape must not fail the endpoint.
 */
final class QueueMetrics
{
    public function __construct(private readonly QueueManager $queues, private readonly Container $container) {}

    /**
     * @param  list<string>|null  $queues
     * @return array{connection: string, collected_at: string, queues: array<string, array{pending: int|null, delayed: int|null, reserved: int|null, total: int|null, oldest_pending_age_seconds: int|null, estimated_wait_seconds: float|int|null}>, failed: array{stored: int|null, horizon_recent: int|null}}
     */
    public function snapshot(?array $queues = null, ?string $connection = null): array
    {
        $connection ??= (string) config('queue.default');
        $now = CarbonImmutable::now();
        $waits = $this->horizonWaits($connection);

        $report = [];
        foreach (self::names($queues) as $queue) {
            $report[$queue] = $this->depth($connection, $queue, $now->getTimestamp())
                + ['estimated_wait_seconds' => $waits[$connection.':'.$queue] ?? null];
        }

        return [
            'connection' => $connection,
            'collected_at' => $now->toIso8601ZuluString(),
            'queues' => $report,
            'failed' => ['stored' => $this->storedFailures(), 'horizon_recent' => $this->horizonRecentFailures($connection)],
        ];
    }

    /**
     * Jobs not finished yet (pending + delayed + reserved) over the given queues; null if unreadable.
     *
     * @param  list<string>|null  $queues
     */
    public function backlog(?array $queues = null, ?string $connection = null): ?int
    {
        $connection ??= (string) config('queue.default');
        $total = 0;
        foreach (self::names($queues) as $queue) {
            $depth = $this->depth($connection, $queue, time());
            if ($depth['total'] === null) {
                return null;
            }
            $total += $depth['total'];
        }

        return $total;
    }

    /**
     * @param  list<string>|null  $queues
     * @return list<string>
     */
    private static function names(?array $queues): array
    {
        return $queues === null || $queues === [] ? Queues::all() : $queues;
    }

    /** @return array{pending: int|null, delayed: int|null, reserved: int|null, total: int|null, oldest_pending_age_seconds: int|null} */
    private function depth(string $connection, string $queue, int $now): array
    {
        try {
            $driver = $this->queues->connection($connection);
            // Part of the Queue contract since Laravel 11 (Redis, Database, Sync, ... implement them).
            $pending = (int) $driver->pendingSize($queue);
            $delayed = (int) $driver->delayedSize($queue);
            $reserved = (int) $driver->reservedSize($queue);
            $created = $driver->creationTimeOfOldestPendingJob($queue);
            $total = $pending + $delayed + $reserved;

            return [
                'pending' => $pending, 'delayed' => $delayed, 'reserved' => $reserved, 'total' => $total,
                'oldest_pending_age_seconds' => is_numeric($created) ? max(0, $now - (int) $created) : null,
            ];
        } catch (Throwable) {
            return ['pending' => null, 'delayed' => null, 'reserved' => null, 'total' => null, 'oldest_pending_age_seconds' => null];
        }
    }

    /** @return array<string, float|int> "connection:queue" => seconds */
    private function horizonWaits(string $connection): array
    {
        if (! $this->isRedis($connection)) {
            return [];
        }
        try {
            /** @var array<string, float|int> $waits */
            $waits = $this->container->make(WaitTimeCalculator::class)->calculate();

            return $waits;
        } catch (Throwable) {
            return [];
        }
    }

    private function storedFailures(): ?int
    {
        try {
            /** @var mixed $failer */
            $failer = $this->container->make('queue.failer');

            return $failer instanceof CountableFailedJobProvider ? (int) $failer->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function horizonRecentFailures(string $connection): ?int
    {
        if (! $this->isRedis($connection)) {
            return null;
        }
        try {
            return (int) $this->container->make(JobRepository::class)->countRecentlyFailed();
        } catch (Throwable) {
            return null;
        }
    }

    private function isRedis(string $connection): bool
    {
        return config("queue.connections.{$connection}.driver") === 'redis';
    }
}
