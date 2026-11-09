<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics\Collectors;

use Modules\Shared\Metrics\Names;
use Modules\Shared\Metrics\ScrapeCache;
use Modules\Shared\Queue\QueueMetrics;
use Prometheus\CollectorRegistry;
use Throwable;

/**
 * Queue gauges computed AT SCRAPE TIME from QueueMetrics::snapshot() (Task 6; never re-implemented here), cached
 * for a few seconds in the metrics db. A null value means "unreadable": the sample is omitted, never 0.
 */
final class QueueGauges
{
    public const CACHE_KEY = 'queues';

    public function __construct(
        private readonly QueueMetrics $queues,
        private readonly ScrapeCache $cache,
        private readonly int $ttlSeconds,
    ) {}

    public function collect(CollectorRegistry $registry): void
    {
        try {
            /** @var array{queues?: array<string, array<string, int|float|null>>, failed?: array{stored?: int|null, horizon_recent?: int|null}} $snapshot */
            $snapshot = $this->cache->remember(self::CACHE_KEY, $this->ttlSeconds, fn (): array => $this->queues->snapshot());
        } catch (Throwable) {
            return; // the endpoint must answer even when the queue driver is down
        }

        $gauges = [
            'pending' => [Names::SCRAPE_QUEUE_PENDING, 'Jobs ready to be picked up (driver pendingSize, exact at scrape time), by queue.'],
            'delayed' => [Names::SCRAPE_QUEUE_DELAYED, 'Jobs waiting for their delay or backoff (driver delayedSize), by queue.'],
            'reserved' => [Names::SCRAPE_QUEUE_RESERVED, 'Jobs currently held by a worker (driver reservedSize), by queue.'],
            'oldest_pending_age_seconds' => [Names::SCRAPE_QUEUE_OLDEST_AGE, 'EXACT age in seconds of the oldest pending job (now - createdAt from the head payload). Absent when the queue is empty or unreadable.'],
            'estimated_wait_seconds' => [Names::SCRAPE_QUEUE_ESTIMATED_WAIT, 'Horizon WaitTimeCalculator ESTIMATE (queue size x average runtime / processes) built from Horizon metric snapshots. Not a measurement of any real job; absent when Horizon has no data.'],
        ];

        foreach ($snapshot['queues'] ?? [] as $queue => $values) {
            foreach ($gauges as $field => [$name, $help]) {
                $value = $values[$field] ?? null;
                if ($value === null || ! is_numeric($value)) {
                    continue;
                }
                $registry->getOrRegisterGauge('', $name, $help, ['queue'])->set((float) $value, [(string) $queue]);
            }
        }

        $stored = $snapshot['failed']['stored'] ?? null;
        if (is_numeric($stored)) {
            $registry->getOrRegisterGauge('', Names::SCRAPE_QUEUE_FAILED_STORED, 'Rows in the failed_jobs table (jobs that exhausted their retries), as counted at scrape time.')
                ->set((float) $stored);
        }
        $recent = $snapshot['failed']['horizon_recent'] ?? null;
        if (is_numeric($recent)) {
            $registry->getOrRegisterGauge('', Names::SCRAPE_QUEUE_FAILED_RECENT, 'Horizon recently-failed counter (its own retention window, see config/horizon.php trim.recent_failed).')
                ->set((float) $recent);
        }
    }
}
