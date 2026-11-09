# ADR 0010: Metrics storage in a dedicated Redis db, bounded cardinality

Status: accepted (Task 9). Related: ADR 0002 (Redis roles), docs/observability.md.

## Context
Octane runs 2 FrankenPHP workers with `--max-requests=500` and Horizon runs several worker processes: metric state
kept in PHP memory would be per process and lost on every recycle, so counters and histograms must live in Redis.
`promphp/prometheus_client_php` (v2.15.1, `php ^8.2`, Redis storage through phpredis) keeps every metric as a hash
and updates it atomically with Lua (`HINCRBYFLOAT`), which gives exact counts across processes
(tests/Concurrency/MetricsCounterTest.php). PHP 8.5 is accepted by its constraint; its own CI matrix was not checked
for 8.5, so the install is `UNTESTED` until `make test` runs in the container.

The `state` db (redis-queue db 2) holds correctness data: limiter windows, idempotency fast path, chat state. The
metrics library needs `SCAN`/`DEL` resets (`metrics:reset` for Task 10) and key scans at collect time. The same
instance is `noeviction` with `maxmemory 300mb` and also holds the queues (db 0): a metric that grows without bound
(a label carrying ids or raw URLs) would fill the instance and turn into WRITE ERRORS for queues and state.

## Decision
1. Metrics use a DEDICATED Laravel Redis connection `metrics` (config/database.php): same instance as `state`
   (redis-queue, noeviction, AOF) but its own db `REDIS_METRICS_DB` (default 3; `make test-int` uses 13), with the
   library prefix `metrics:` on top of the global phpredis prefix. `metrics:reset` wipes only that prefix in that db.
2. Cardinality is bounded by construction, not by hope:
   - Every metric is declared once in `Modules\Shared\Metrics\Catalog` with fixed label NAMES; the memory driver
     (tests) throws on unknown metrics/labels, the redis driver drops and counts them.
   - Label VALUES come from finite sets: route name or URI template (the route table), method (fixed list), status
     class, queue (`Queues::all()`), job short class name, limiter name (`config/rate-limits.php`), outcome, role.
     Unmatched routes are the single value `unmatched`. `MetricsEndpointTest` hits 200 random unknown paths and
     asserts the series count does not move.
   - `aghigh_metrics_series` (samples per exposition) is a watchdog panel; budget: `provisional` 5 000 series.
3. Memory budget (provisional, `TODO(measure)` with `redis-cli -n 3 INFO memory` after a `make k6 s=smoke`):
   one hash field per label set per bucket, ~100 B each. Worst case today: HTTP histogram ~60 routes x 7 methods x
   15 buckets ~ 6 300 fields plus counters: well under 5 MB, i.e. < 2% of the 300 MB instance. Alert
   `AghighRedisMemoryHigh` (80%) / `AghighRedisQueueNearMaxmemory` (95%) cover the instance; `redis_db_keys{db="db3"}`
   on the Redis dashboard shows the metrics keyspace separately.
4. Writes never break a request or a job: every driver call is guarded, failures go to a process-local counter
   (`Metrics\Failures`), are logged at most once a minute and flushed into `aghigh_metrics_write_failures_total`
   when Redis answers again; HTTP metrics are recorded in `terminate()`. The scrape exposes
   `aghigh_metrics_backend_up` for the window in which nothing could be flushed.

## Consequences
- A second Redis db on the queue instance (not a second instance): no new container, but a metrics bug can still
  consume queue memory. The alerts and the watchdog exist for that reason; a separate instance is the escalation
  path if Task 10 shows the metrics keyspace growing.
- `METRICS_ENABLED=false` selects the null driver so Task 10 can measure the overhead of recording metrics.
- Scrape-time gauges (queues, Reverb) are cached in the same db for 5 s / 10 s so several scrapers cannot stampede
  Horizon or Reverb.
