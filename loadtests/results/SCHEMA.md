# Result file schema (schema_version 1)

Every scenario writes ONE file per run, all with the same shape:

```
loadtests/results/<scenario>-<variant>-<UTC timestamp>.json      e.g. order-flood-atomic-20250131T142501Z.json
loadtests/results/<scenario>-<variant>-<UTC timestamp>.samples.ndjson   resource sampler (one JSON line per tick)
loadtests/results/<scenario>-<variant>-<UTC timestamp>.metrics.prom     /internal/metrics snapshot after the run
loadtests/results/summary-<make target>-<UTC timestamp>.json     median/min/max of a `make k6-repeat` series
```

k6 (`loadtests/lib/summary.js`, `handleSummary`) writes the file; `loadtests/scripts/after-run.sh` then fills
`host` and `invariants` and appends to `notes`. Task 12's `bench-report.php` renders these files.

The directory is git-ignored (only `.gitkeep` and this file are tracked). Curated runs are copied by hand to
`docs/benchmarks/` **keeping the same file names** (see `docs/benchmarks/README.md`).

Rules: a value that was not measured is `null`, never `0` and never a guess. Times are milliseconds unless the key
says otherwise. Timestamps are UTC ISO 8601.

## Top level

| Key | Type | Meaning |
|---|---|---|
| `schema_version` | int | `1`. Bump on any incompatible change and teach `bench-report.php` both. |
| `scenario` | string | `catalog`, `order-flood`, `spike`, `soak`, `websocket-chat`, `auth-abuse`, `metrics-overhead` |
| `variant` | string | e.g. `naive`, `optimized-cold`, `atomic`, `fanout-r2`, `reconnect-jitter-r1`, `metrics-off`, `default` |
| `run` | object | What ran, on which code, with which parameters and environment (below) |
| `host` | object or null | Machine and versions, from `host-info.sh` (below). `null` until after-run ran |
| `metrics` | object | The measurements (below) |
| `thresholds` | array | One entry per threshold expression (below) |
| `checks` | array | `{name, group, passes, fails, rate}` for every k6 `check()` |
| `invariants` | object or null | Filled by `after-run.sh` (below). `null` = after-run did not run: the file is incomplete |
| `notes` | string[] | Human-readable caveats written by the scenario and by after-run |

## `run`

| Key | Meaning |
|---|---|
| `id` | UTC timestamp of the run, `YYYYMMDDTHHMMSSZ` (also in the file name) |
| `git_sha`, `git_dirty` | Commit of the working tree and whether it had uncommitted changes (`null` when run outside the make targets) |
| `started_at`, `finished_at`, `duration_seconds` | k6 test run, setup included |
| `k6_version` | First line of `k6 version` from the pinned image |
| `base_url` | `http://traefik` inside compose: Traefik overhead is part of every number |
| `params` | Scenario parameters (rate, durations, users, stock, catalog size `catalog_products`, ...). Load shapes, not results |
| `env.APP_ENV`, `env.APP_DEBUG`, `env.LOG_LEVEL` | Read from the running app container, not from the files |
| `env.STOCK_STRATEGY`, `env.STOCK_NAIVE_DELAY_MS` | Effective stock strategy of the app containers |
| `env.METRICS_ENABLED`, `env.SENTRY_TRACES_SAMPLE_RATE`, `env.SENTRY_DSN_SET` | Observability switches |
| `env.RATE_LIMIT_MULTIPLIER` | `null` = unset (limits at their configured values, as in `auth-abuse`) |
| `env.REVERB_SCALE` | Number of Reverb replicas |
| `env.observability_stack` | `up`, `down` or `unknown`. Latency numbers are baseline material only with `down` |

## `host` (from `loadtests/scripts/host-info.sh`)

`cpu.model`, `cpu.logical_cpus`, `memory_total_bytes`, `os`, `kernel`, `wsl2` (bool), `wslconfig` (`memory`,
`processors`, `swap` when a `.wslconfig` was found), `power_source` (`ac`, `battery` or `unknown (confirm AC power
manually)`), `host_ulimit_nofile`, `docker.{client_version, server_version, compose_version, context, engine}`
(`engine.operating_system` tells Docker Desktop from an engine inside WSL), `compose_limits` (`cpus` and `mem_limit`
per service as resolved by compose), `versions.{php, laravel, octane_workers, postgres, redis_queue, redis_cache,
pgbouncer, reverb_replicas}`.

## `metrics`

| Key | Meaning |
|---|---|
| `p50`, `p90`, `p95`, `p99` | HTTP request duration (ms) of the scenario's main phase (`http.scope` names it). Warm-up is never included |
| `rps` | Requests per second of the main phase (`http.rps_basis` says how it was derived) |
| `error_rate` | Share of requests k6 counted as failed in the main phase. Scenarios where 409/401/429 are valid answers declare them as expected, so this is the rate of *unexpected* statuses |
| `unit` | `"ms"` |
| `http` | `{scope, p50, p90, p95, p99, avg, min, max, requests, rps, rps_basis, error_rate}` of the main phase |
| `phases` | The same block per phase tag (`warmup`, `main`, `baseline`, `spike`, `recovery`, `stuffing`, ...) |
| `iterations` | `{count, rate, dropped}`. `dropped > 0` means k6 could not apply the configured arrival rate |
| `counters` | Scenario counters, all prefixed `lt_` (e.g. `lt_orders_created`, `lt_http_429`, `lt_ws_connect_failed`) |
| `rates` | `{rate, passes, fails}` per rate metric (e.g. `lt_unexpected_response`, `lt_ws_delivery_missed`) |
| `trends` | `{p50, p90, p95, p99, avg, min, max, count}` per trend metric (e.g. `lt_ws_delivery_ms`) |
| `gauges` | `{value, min, max}` per gauge metric |
| `derived` | Scenario-specific values computed from the above, e.g. `seconds_back_under_p95_budget` and `timeline` (spike), `keys_created` vs `stock` (order-flood), `ceiling_approx_connections` (websocket ceiling), `peak_attempts_per_second` (reconnect) |

Sub-metrics keep their k6 name, e.g. `lt_http_5xx{phase:main}`.

## `thresholds[]`

| Key | Meaning |
|---|---|
| `metric`, `expression` | e.g. `http_req_duration{phase:main}`, `p(95)<500` |
| `kind` | `performance` (a budget from `loadtests/config/thresholds.json`) or `assertion` (a correctness condition) |
| `value` | The observed value of the aggregate named in the expression |
| `passed` | k6's verdict for the expression |
| `provisional` | `true` until the entry in `thresholds.json` is `status: baseline`. Assertions are never provisional |
| `status` | `provisional`, `baseline` or `assertion` |
| `baseline_date`, `baseline_commit` | The baseline the budget was calibrated on (`null` while provisional) |

A missed provisional threshold is recorded and does not fail the make target; a failed assertion or baseline
threshold does.

## `invariants` (from `after-run.sh`)

| Key | Present when | Content |
|---|---|---|
| `orders` | always | Output of `orders:verify-invariants --json`: `{ok, orders_checked, violation_count, violations[]}` or `{error}` |
| `queues` | scenarios that create jobs | Output of `queue:drain-time --json` (`drained`, `elapsed_seconds`, `polls`, `backlog`) plus `failed_jobs` and `seconds_between_k6_end_and_measurement_start` |
| `chat` | `websocket-chat` | Output of `chat:verify-invariants --rebuild --json` |
| `stock` | `order-flood` | The flooded product's `stock_items` row, `reservations_by_status`, `units_beyond_initial_stock`, `users_with_more_than_one_order` |
| `app_metrics` | always | `snapshot_file`, `aghigh_metrics_series`; for `metrics-overhead` also `metrics_redis_keys`, `metrics_redis_memory_bytes` |
| `resources` | sampler on | Per series `{samples, first, last, min, peak, slope_per_hour}`: Redis `used_memory` of both instances, Postgres connections, PgBouncer `cl_active`/`cl_waiting`/`sv_active`/`sv_idle`/`maxwait_seconds`, per container `mem_bytes`/`cpu_pct`/`pids`, per Reverb replica `established_sockets`/`open_fds`, `aghigh_metrics_series` |
| `auth` | `auth-abuse` | `legit_login_other_ip {account, status, client_ip_seen_by_app, ok}` and `security_events {total, by_message}` |
| `verdict` | always | `{passed, expected_violations, failures[], k6_exit_code}` |

`verdict.expected_violations` is `true` only for `order-flood`/`naive`: that run is *supposed* to oversell,
`orders.violation_count` is the evidence, and `verdict.passed` then means "it oversold as expected". For every other
run any violation makes `verdict.passed` false.

## Summary files

`summary-*.json` (`loadtests/scripts/summarize.sh`): `groups[]` with `scenario`, `variant`, `runs`, `enough_runs`
(3 or more), `run_ids`, `git_shas`, `any_dirty`, `observability_stack`, `all_verdicts_passed`, and
`{median, min, max, n}` for `p50`, `p90`, `p95`, `p99`, `rps`, `error_rate`.
