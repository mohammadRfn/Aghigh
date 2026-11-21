# Benchmarks (hand-off to Task 12)

This directory holds the **curated** load-test results that the README and `bench-report.php` (Task 12) are built
from. Nothing here is generated: a file is here because someone ran the scenario, looked at it and copied it.

**Status: baselines were recorded between 2026-11-19 and 2026-11-21 (7 scenarios, 3 runs each, one laptop); the files are in this directory. `TODO(measure)` marks what is still unmeasured.**
Do not write a number into any document that is not in one of the files in this directory.

## Which files to copy

After a clean series (`make k6-repeat TARGET=<target> RUNS=3`, observability stack down, `verdict.passed` true):

| Copy from `loadtests/results/` | Keep the name | Why |
|---|---|---|
| `<scenario>-<variant>-<UTC>.json` of all 3 runs of every variant you report | yes, unchanged | The schema (`loadtests/results/SCHEMA.md`) is what `bench-report.php` reads; the name carries scenario, variant and run id |
| `summary-<target>-<UTC>.json` of the series | yes | median / min / max that the README quotes |
| `<scenario>-<variant>-<UTC>.samples.ndjson` for `soak`, `spike`, `websocket-chat` and `metrics-overhead` | yes | Source of the peaks in `capacity-handoff.md` and of the soak slopes |
| `<scenario>-<variant>-<UTC>.metrics.prom` | optional | Only when a document quotes a metric value from it |

Do not copy: runs with `run.git_dirty: true`, runs with `run.env.observability_stack: "up"` (except the one
`metrics-overhead`/`obs-up` run, which is for resource cost and screenshots only), runs whose
`invariants.verdict.passed` is false, and series with fewer than 3 runs.

Check a set before committing it:

```
make k6-summarize FILES="docs/benchmarks/catalog-*.json"
```

## Topic to benchmark mapping

| Topic shown in the README | Scenario and variants | Make command | Where the evidence is in the result file |
|---|---|---|---|
| Race conditions (overselling) | `order-flood`: `naive` vs `atomic` vs `locking` | `make k6-order-flood STRATEGY=all` | `invariants.orders.violation_count` (naive > 0, expected), `invariants.stock.units_beyond_initial_stock`, `metrics.derived.keys_created` vs `stock`, latency per strategy in `metrics.p95` |
| N+1 and indexing | `catalog`: `naive` vs `optimized-cold` vs `optimized-warm` | `make k6-catalog` | `metrics.p50..p99`, `metrics.rps`, `run.params.catalog_products` |
| Rate limiting | `auth-abuse` | `make k6-auth-abuse` | `metrics.derived.first_429_at_attempt_min/max` vs `configured_limit`, `metrics.counters.lt_auth_xff_trusted`, `invariants.auth.legit_login_other_ip`, `invariants.auth.security_events` |
| Reconnect (thundering herd) | `websocket-chat`: `reconnect-jitter` vs `reconnect-immediate` | `make k6-websocket-chat MODE=reconnect` | `metrics.derived.peak_attempts_per_second`, `recover_p95_ms`, `reconnect_failed_attempts`, `attempts_timeline` |
| Idempotency | `order-flood` replays (any safe strategy) | `make k6-order-flood STRATEGY=atomic` | `metrics.derived.replays_sent`, `replays_answered_with_original`, `replay_mismatches` (must be 0), `invariants.stock.users_with_more_than_one_order` (must be 0) |
| Metrics cost | `metrics-overhead`: `metrics-off` vs `metrics-on` vs `traces-on` | `make k6-metrics-overhead` | `metrics.p95` and `metrics.rps` per variant, `invariants.app_metrics.aghigh_metrics_series`, `metrics_redis_memory_bytes` |
| Token reuse detection | **no benchmark** | `make test-int` | This is a correctness property, not a performance one, so there is nothing to measure under load. It is proven by the functional tests `Modules/Identity/tests/Concurrency/RefreshRotationTest.php` (real parallel processes) and `Modules/Identity/tests/Feature/TokenAttacksTest.php`. Link those; do not present a number for it |

Also available, not mapped to a README topic: `spike` (recovery time and queue drain), `soak` (leak slopes),
`websocket-chat` `ceiling` and `fanout` (per-replica ceiling, delivery latency, cross-replica with `REVERB_SCALE=2`).

## What may be compared

Only **relative** comparisons between variants that were run back to back on the same machine, with the same
parameters, in the same series: naive vs optimized, strategy A vs B, jitter vs immediate, metrics off vs on.
Absolute throughput and latency are properties of that one machine (and of k6 sharing its CPU) and are **not**
comparable across machines. Always publish `host` and `run.params` next to a number. Details: `loadtests/README.md`.
