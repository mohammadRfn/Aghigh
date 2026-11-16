# Capacity hand-off to Task 11 (production compose and sizing)

Task 11 sizes limits, pools and grace periods. These are the measurements it needs, where each one comes from, and
the exact command that produces it. **Nothing has been measured yet: every value is `TODO(measure)`.** Fill a cell
only from a result file that was copied to `docs/benchmarks/` (see `README.md` there) and name that file.

All peaks come from the resource sampler (`loadtests/scripts/sampler.sh`), aggregated by `after-run.sh` into
`invariants.resources` of the result file: per series `{first, last, min, peak, slope_per_hour}`.
Use the runs with the heaviest relevant load: `spike` for bursts, `soak` for steady state and growth,
`websocket-chat` for sockets.

| # | Measurement | Value | Source in the result file | Command |
|---|---|---|---|---|
| 1 | Peak Redis `used_memory`, redis-queue (queues, Horizon, state, metrics) | `TODO(measure)` | `invariants.resources.redis.queue_used_memory_bytes.peak` | `make k6-spike` and `make k6-soak DURATION=2h` (take the larger) |
| 2 | Peak Redis `used_memory`, redis-cache | `TODO(measure)` | `invariants.resources.redis.cache_used_memory_bytes.peak` | `make k6-catalog VARIANT=optimized-warm`, `make k6-soak DURATION=2h` |
| 3 | Redis memory growth over time (leak signal) | `TODO(measure)` | `...queue_used_memory_bytes.slope_per_hour`, `...cache_used_memory_bytes.slope_per_hour` | `make k6-soak DURATION=2h` |
| 4 | PgBouncer pool in use at peak (`sv_active`, against `PGBOUNCER_POOL_SIZE`) | `TODO(measure)` | `invariants.resources.pgbouncer.sv_active.peak` | `make k6-spike` |
| 5 | PgBouncer clients waiting for a server connection (`cl_waiting`) and longest wait | `TODO(measure)` | `invariants.resources.pgbouncer.cl_waiting.peak`, `...maxwait_seconds.peak` | `make k6-spike` |
| 6 | Postgres connections at peak (against `max_connections`) | `TODO(measure)` | `invariants.resources.postgres.connections.peak` | `make k6-spike`, `make k6-order-flood STRATEGY=locking` |
| 7 | Reverb connections per replica at the approximate ceiling | `TODO(measure)` | `invariants.resources.reverb.<container>.established_sockets.peak`, `metrics.derived.ceiling_approx_connections` | `make k6-websocket-chat MODE=ceiling REVERB_SCALE=1` and `REVERB_SCALE=2` |
| 8 | Reverb open file descriptors per replica (against `nofile`) | `TODO(measure)` | `invariants.resources.reverb.<container>.open_fds.peak` | same runs as 7 |
| 9 | Longest job runtime p99 (basis for Horizon `stop_grace_period`) | `TODO(measure)` | `<run>.metrics.prom`: `histogram_quantile(0.99, ...)` over the job runtime histogram, per job class (see below) | `make k6-spike`, then read the snapshot |
| 10 | `queue:drain-time` after the spike | `TODO(measure)` | `invariants.queues.elapsed_seconds`, `invariants.queues.failed_jobs` | `make k6-spike` |
| 11 | Worker memory: Horizon container RSS at peak | `TODO(measure)` | `invariants.resources.containers.<horizon container>.mem_bytes.peak` | `make k6-spike`, `make k6-soak DURATION=2h` |
| 12 | App (Octane) container memory at peak and its slope | `TODO(measure)` | `invariants.resources.containers.<app container>.mem_bytes.{peak,slope_per_hour}` | `make k6-soak DURATION=2h` |
| 13 | Metrics keyspace: series count and Redis memory of the metrics db | `TODO(measure)` | `invariants.app_metrics.aghigh_metrics_series`, `metrics_redis_memory_bytes` | `make k6-metrics-overhead METRICS=metrics-on` |

## Notes for whoever fills this in

- **Row 9 (job runtime p99).** The app exports job runtime as a histogram on `/internal/metrics`; `after-run.sh`
  saves the snapshot as `<scenario>-<variant>-<UTC>.metrics.prom` and that works without Prometheus. A quantile from
  cumulative buckets needs either Prometheus (`make obs-up`, then `histogram_quantile(0.99, sum by (le, job)
  (rate(<job runtime histogram>_bucket[5m])))`; use the metric name declared in `Modules\Shared\Metrics\Names`) or
  reading the `le` bucket in which the 99th percentile falls straight from the snapshot. The bucket boundary is an
  upper bound, which is the safe direction for a grace period. Run the observability stack only for this reading,
  never for the latency numbers of the same run. `TODO(measure)`.
- **Rows 1 to 3.** `used_memory` is per Redis *instance*. redis-queue holds four dbs (queue, state, metrics, and the
  Horizon data inside the queue db); only the metrics db is itemized separately (row 13). ADR 0010 keeps the
  upgrade path: if the metrics keyspace grows, move it to its own Redis instance.
- **Rows 4 to 6.** PgBouncer runs in transaction mode: `cl_waiting > 0` for more than a moment means the pool, not
  Postgres, is the queue. Compare `sv_active.peak` with `PGBOUNCER_POOL_SIZE` and row 6 with Postgres
  `max_connections` minus the direct connections listed in `docs/queues.md`.
- **Rows 7 and 8.** The ceiling is approximate by construction (see the header of
  `loadtests/scenarios/websocket-chat.js`). With `REVERB_SCALE=2` record the per-replica split as observed; do not
  assume it is even.
- **Rows 11 and 12.** These are container totals from `docker stats`, not per-process RSS. Divide by the worker
  count only as an estimate and say so.
- Container limits in force during a run are in `host.compose_limits` of the same result file. A peak that sits at
  the limit means the limit, not the workload, set the number: raise the limit and measure again.
- Sizing must come from the machine class that will run production. Numbers from a laptop or WSL2 are
  **not transferable** in absolute terms; they are still valid for ratios (peak vs steady, per-connection cost).
