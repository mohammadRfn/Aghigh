<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

/**
 * Metric names, one naming scheme: aghigh_<subsystem>_<what>_<unit|total>. Everything written through the
 * Metrics contract is declared in Catalog; the SCRAPE_* names are computed at scrape time (QueueMetrics,
 * Reverb) and are not writable through the contract.
 */
final class Names
{
    // HTTP (RecordHttpMetrics, recorded in terminate())
    public const HTTP_REQUESTS = 'aghigh_http_requests_total';

    public const HTTP_DURATION = 'aghigh_http_request_duration_seconds';

    // Queue events (QueueEventMetrics)
    public const QUEUE_JOB_WAIT = 'aghigh_queue_job_wait_seconds';

    public const QUEUE_JOB_RUNTIME = 'aghigh_queue_job_runtime_seconds';

    public const QUEUE_JOBS_PROCESSED = 'aghigh_queue_jobs_processed_total';

    public const QUEUE_JOBS_FAILED = 'aghigh_queue_jobs_failed_total';

    // Domain counters (listeners live in the owning module)
    public const ORDERS = 'aghigh_orders_total';

    public const CHAT_MESSAGES = 'aghigh_chat_messages_total';

    public const CHAT_CLAIMS = 'aghigh_chat_claims_total';

    public const CHAT_CLOSES = 'aghigh_chat_closes_total';

    // Shared infrastructure counters
    public const RATE_LIMIT_HITS = 'aghigh_rate_limit_hits_total';

    public const STATE_STORE_ERRORS = 'aghigh_state_store_errors_total';

    public const METRICS_WRITE_FAILURES = 'aghigh_metrics_write_failures_total';

    // Scrape-time gauges (Collectors\QueueGauges, computed from QueueMetrics::snapshot(), cached 5 s)
    public const SCRAPE_QUEUE_PENDING = 'aghigh_queue_pending_jobs';

    public const SCRAPE_QUEUE_DELAYED = 'aghigh_queue_delayed_jobs';

    public const SCRAPE_QUEUE_RESERVED = 'aghigh_queue_reserved_jobs';

    public const SCRAPE_QUEUE_OLDEST_AGE = 'aghigh_queue_oldest_pending_age_seconds';

    public const SCRAPE_QUEUE_ESTIMATED_WAIT = 'aghigh_queue_estimated_wait_seconds';

    public const SCRAPE_QUEUE_FAILED_STORED = 'aghigh_queue_failed_jobs_stored';

    public const SCRAPE_QUEUE_FAILED_RECENT = 'aghigh_queue_horizon_recent_failed_jobs';

    // Scrape-time gauges (Collectors\ReverbGauges, signed Pusher API call, cached 10 s)
    public const SCRAPE_REVERB_CONNECTIONS = 'reverb_pusher_api_connections';

    public const SCRAPE_REVERB_UP = 'reverb_scrape_up';

    // Scrape health
    public const SCRAPE_BACKEND_UP = 'aghigh_metrics_backend_up';

    public const SCRAPE_SERIES = 'aghigh_metrics_series';

    public const SCRAPE_ENABLED = 'aghigh_metrics_enabled';
}
