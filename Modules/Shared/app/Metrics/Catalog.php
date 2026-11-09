<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

/**
 * Every writable metric, declared once (name, type, HELP, label NAMES, buckets). Label names are fixed here so
 * a typo or a new high-cardinality label cannot appear silently; label VALUES are sanitised by LabelValue.
 * Adding a metric = add a Names constant + an entry here + a dashboard panel (docs/observability.md).
 */
final class Catalog
{
    /** Octane-tuned latency buckets, 5 ms to 10 s (provisional). */
    public const HTTP_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.15, 0.25, 0.4, 0.6, 1.0, 1.5, 2.5, 5.0, 10.0];

    /** Queue wait: shows the Horizon pickup delay seen in Task 8 (1 to 3 s) as well as real backlog (minutes). */
    public const WAIT_BUCKETS = [0.05, 0.1, 0.25, 0.5, 1.0, 2.0, 3.0, 5.0, 10.0, 30.0, 60.0, 120.0, 300.0];

    public const RUNTIME_BUCKETS = [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0];

    /** @var array<string, MetricDefinition>|null */
    private static ?array $definitions = null;

    public static function get(string $name): ?MetricDefinition
    {
        return self::all()[$name] ?? null;
    }

    /** @return array<string, MetricDefinition> */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [
            new MetricDefinition(Names::HTTP_REQUESTS, 'counter',
                'HTTP requests handled by the app, by route name (or URI template), method and status class. Unmatched routes are labelled route="unmatched". /up and /internal/metrics are not counted.',
                ['route', 'method', 'status_class']),
            new MetricDefinition(Names::HTTP_DURATION, 'histogram',
                'HTTP request duration in seconds measured in the terminate() phase (handle start to response sent), by route and method.',
                ['route', 'method'], self::HTTP_BUCKETS),
            new MetricDefinition(Names::QUEUE_JOB_WAIT, 'histogram',
                'Seconds between the push of a job (Horizon pushedAt, microsecond) and the start of its FIRST attempt, by queue and job class. Delayed jobs and retries are excluded, so this is pure pickup delay plus backlog.',
                ['queue', 'job'], self::WAIT_BUCKETS),
            new MetricDefinition(Names::QUEUE_JOB_RUNTIME, 'histogram',
                'Seconds a job attempt ran (JobProcessing to JobProcessed or exception), by queue and job class.',
                ['queue', 'job'], self::RUNTIME_BUCKETS),
            new MetricDefinition(Names::QUEUE_JOBS_PROCESSED, 'counter',
                'Job attempts that finished without an exception, by queue and job class.',
                ['queue', 'job']),
            new MetricDefinition(Names::QUEUE_JOBS_FAILED, 'counter',
                'Jobs that failed for good (JobFailed: no retries left), by queue and job class.',
                ['queue', 'job']),
            new MetricDefinition(Names::ORDERS, 'counter',
                'Order lifecycle outcomes from the Ordering events relayed through the outbox (at-least-once: a redelivered event counts twice), by outcome.',
                ['outcome']),
            new MetricDefinition(Names::CHAT_MESSAGES, 'counter',
                'Chat messages persisted (MessagePersisted, after commit), by sender role.',
                ['sender_role']),
            new MetricDefinition(Names::CHAT_CLAIMS, 'counter',
                'Support conversations claimed by an agent (ConversationClaimed).'),
            new MetricDefinition(Names::CHAT_CLOSES, 'counter',
                'Conversations closed (ConversationClosed), by the role that closed them.',
                ['closed_by_role']),
            new MetricDefinition(Names::RATE_LIMIT_HITS, 'counter',
                'Requests rejected by a limiter (RateLimitHit), by limiter NAME from config/rate-limits.php and driver. Never by key or IP.',
                ['limiter', 'driver']),
            new MetricDefinition(Names::STATE_STORE_ERRORS, 'counter',
                'Redis state-store failures caught by the component that tolerates them: limiter (RateLimitBackendFailed, with its fail mode), idempotency (Ordering cache), chat_state (not incremented today: RedisChatState has no catch block, its errors surface as 5xx), metrics (this library, flushed from the process-local counter).',
                ['component']),
            new MetricDefinition(Names::METRICS_WRITE_FAILURES, 'counter',
                'Metric writes that failed in a PHP process and were flushed to Redis once it answered again. While Redis is down nothing can be flushed: use aghigh_metrics_backend_up for that window.',
                ['driver']),
        ];

        $byName = [];
        foreach ($definitions as $definition) {
            $byName[$definition->name] = $definition;
        }

        return self::$definitions = $byName;
    }
}
