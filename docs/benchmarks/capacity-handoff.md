# Capacity hand-off to Task 11 (production compose and sizing)

Task 11 sizes limits, pools and grace periods. These are the measurements it needs, where each one comes from, and
the exact command that produces it. **Rows 1 to 8 and 10 to 13 are filled from the baseline files in this directory (one laptop, 2026-11-19 to 2026-11-21); row 9 needs the `.metrics.prom` snapshot, which is not copied here, so it stays `TODO(measure)`. The sampler runs every 10 s (soak 30 s), so a peak shorter than that can be missed.** Fill a cell
only from a result file that was copied to `docs/benchmarks/` (see `README.md` there) and name that file.

All peaks come from the resource sampler (`loadtests/scripts/sampler.sh`), aggregated by `after-run.sh` into
`invariants.resources` of the result file: per series `{first, last, min, peak, slope_per_hour}`.
Use the runs with the heaviest relevant load: `spike` for bursts, `soak` for steady state and growth,
`websocket-chat` for sockets.

| # | Measurement | Value | Source in the result file | Command |
|---|---|---|---|---|
| 1 | Peak Redis `used_memory`, redis-queue (queues, Horizon, state, metrics) | peak 29.6 MiB. spike 15.5 / 22.4 / 29.1 MiB, soak 18.1 / 29.6 / 29.4 MiB (3 runs each, `spike-default-*.json`, `soak-default-*.json`) | `invariants.resources.redis.queue_used_memory_bytes.peak` | `make k6-spike` and `make k6-soak DURATION=2h` (take the larger) |
| 2 | Peak Redis `used_memory`, redis-cache | peak 2.7 MiB. catalog optimized-warm 2.7 / 2.7 / 2.7 MiB, soak 1.8 / 1.8 / 1.8 MiB (`catalog-optimized-warm-*.json`, `soak-default-*.json`) | `invariants.resources.redis.cache_used_memory_bytes.peak` | `make k6-catalog VARIANT=optimized-warm`, `make k6-soak DURATION=2h` |
| 3 | Redis memory growth over time (leak signal) | 31-minute soak, 3 runs. redis-queue 27.5 / 27.0 / 4.5 MiB/h (high in runs 1 and 2, lower in run 3; peaks 18.1 / 29.6 / 29.4 MiB, runs 2 and 3 peak at about the same value; cause not established), redis-cache -0.02 / -0.01 / 0.00 MiB/h. The 2 h run (`DURATION=2h`) was not done | `...queue_used_memory_bytes.slope_per_hour`, `...cache_used_memory_bytes.slope_per_hour` | `make k6-soak DURATION=2h` |
| 4 | PgBouncer pool in use at peak (`sv_active`, against `PGBOUNCER_POOL_SIZE`) | peak 3 / 2 / 2 server connections in use, against `PGBOUNCER_POOL_SIZE=20` (spike, 3 runs) | `invariants.resources.pgbouncer.sv_active.peak` | `make k6-spike` |
| 5 | PgBouncer clients waiting for a server connection (`cl_waiting`) and longest wait | cl_waiting peak 0 / 0 / 0, longest wait 0 / 0 / 0 s (spike, 3 runs): no client waited for a server connection | `invariants.resources.pgbouncer.cl_waiting.peak`, `...maxwait_seconds.peak` | `make k6-spike` |
| 6 | Postgres connections at peak (against `max_connections`) | peak 9 / 9 / 9 during the spike, 4 / 5 / 4 during order-flood/locking (3 runs each); `max_connections` not read in this series | `invariants.resources.postgres.connections.peak` | `make k6-spike`, `make k6-order-flood STRATEGY=locking` |
| 7 | Reverb connections per replica at the approximate ceiling | one replica (`ceiling-r1`), default Traefik budget (0.5 CPU / 256 MiB): approximate ceiling 4203 / 4142 / 4349 sockets (first failed handshake). The sampler (10 s interval) saw at most 2909 / 2945 / 4309 established sockets in the reverb container, below the ceiling because a peak shorter than the interval can be missed. Two replicas not measured | `invariants.resources.reverb.<container>.established_sockets.peak`, `metrics.derived.ceiling_approx_connections` | `make k6-websocket-chat MODE=ceiling REVERB_SCALE=1` and `REVERB_SCALE=2` |
| 8 | Reverb open file descriptors per replica (against `nofile`) | peak open fds in the reverb container 2940 / 2961 / 4343 in the same 3 ceiling runs, one replica; the `nofile` limit is not read in this series | `invariants.resources.reverb.<container>.open_fds.peak` | same runs as 7 |
| 9 | Longest job runtime p99 (basis for Horizon `stop_grace_period`) | `TODO(measure)` | `<run>.metrics.prom`: `histogram_quantile(0.99, ...)` over the job runtime histogram, per job class (see below) | `make k6-spike`, then read the snapshot |
| 10 | `queue:drain-time` after the spike | queue empty when measured: drain 0.005 / 0.006 / 0.005 s, measurement started 0.0 / 0.0 / 0.0 s after k6 ended; failed jobs 12 / 18 / 12 (spike, 3 runs; their cause is not classified) | `invariants.queues.elapsed_seconds`, `invariants.queues.failed_jobs` | `make k6-spike` |
| 11 | Worker memory: Horizon container RSS at peak | peak 693.2 MiB. spike 646.2 / 645.6 / 693.2 MiB, soak 638.7 / 643.0 / 538.4 MiB (container memory from `docker stats`, not RSS per worker); compare with `HORIZON_MEM_LIMIT` before sizing | `invariants.resources.containers.<horizon container>.mem_bytes.peak` | `make k6-spike`, `make k6-soak DURATION=2h` |
| 12 | App (Octane) container memory at peak and its slope | app container peak 189.2 / 190.5 / 210.2 MiB; slope +10.3 / -1.6 / +2.6 MiB/h over 31 minutes (the sign changes between runs: no trend established) | `invariants.resources.containers.<app container>.mem_bytes.{peak,slope_per_hour}` | `make k6-soak DURATION=2h` |
| 13 | Metrics keyspace: series count and Redis memory of the metrics db | 367 / 367 / 367 series, 15972 to 16479 bytes in 9 / 9 / 9 keys (`metrics-on`, 3 runs) | `invariants.app_metrics.aghigh_metrics_series`, `metrics_redis_memory_bytes` | `make k6-metrics-overhead METRICS=metrics-on` |

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
