# Load tests (k6)

Seven k6 scenarios against the real compose stack, through Traefik (`BASE_URL=http://traefik`, so edge overhead is in
every number), with one result schema, invariant checks after every run and no invented numbers.

## Status: what has and has not been run

**No benchmark has been run. There is no baseline. Every threshold is `provisional`, every measurement is
`TODO(measure)`.**

| Part | Status |
|---|---|
| k6 scripts (`scenarios/*.js`, `lib/*.js`) | Parsed with `k6 inspect` and executed with the pinned k6 v1.6.1 against a **mock** HTTP + Pusher server written from the route files and docs. Proves the script logic (tags, thresholds, summary schema, token refresh, replay accounting, WebSocket flows). **UNTESTED against the real app** |
| `scripts/*.sh`, Makefile targets | `bash -n`, and executed end to end against a `docker` **shim** with canned answers (verdict paths, guards, JSON merging). **UNTESTED against real Docker**: compose overlay merge, `docker stats` format, `psql` to PgBouncer, `redis-cli`, Horizon commands, `--wait` |
| `php artisan loadtest:seed-users` and the four new test files | **UNTESTED** (written without a PHP runtime; not even linted). Run `make lint stan test` first |
| `compose.loadtest.yaml`, k6 `user:`/`ulimits`/results mount, `HTTP_BIND`, k6 image fallback (`docker import`) | **UNTESTED**. Check with `make loadtest-config` and `make k6-image-check` |
| Loki query for security events | **UNTESTED** (the recorded count comes from the log files) |
| Assumptions about the real API taken from the code, not from a run | order answers carry `Location` for originals and replays; a customer can post into its own conversation before an agent claims it; a refused correct password during lockout may extend the lock (the script waits `2 x base + 3 s`) |

When something here fails on the real stack, that is a bug in this delivery, not in your setup: fix the script, do
not adjust a number to make it pass.

## Quick start

```
make loadtest-init          # .env.loadtest from the example, results directory
make loadtest-up            # the stack WITH the overlay: APP_ENV=loadtest (make up switches back to local)
make loadtest-config        # confirm APP_ENV=loadtest, APP_DEBUG=false, RATE_LIMIT_MULTIPLIER=20 resolve
make k6-image-check         # pinned image present, k6 version
make k6 s=smoke             # the old smoke test still works
make obs-down               # the observability stack must be down for every baseline
make loadtest-reset         # known state: fresh-seed, 500 users, N=100000 products, stock, clean Redis state/cache
make k6-catalog             # naive -> optimized-cold -> optimized-warm, back to back
```

Needs on the host: Docker Compose >= 2.24 (`required: false` for env files), `jq`, `curl`, `make`, `git`.

## Layout

```
loadtests/
  smoke.js, orders-smoke.js, chat-smoke.js   earlier tasks, unchanged
  config/thresholds.json   every threshold, with status provisional|baseline and the baseline date + commit
  lib/        config.js (env, tags)  auth.js (login pool, per-VU cookie jar, refresh)  checks.js
              thresholds.js (loader)  summary.js (handleSummary, the result schema)  workload.js  steady.js  pusher.js
  scenarios/  catalog  order-flood  spike  soak  websocket-chat  auth-abuse  metrics-overhead
  scripts/    run-scenario.sh  after-run.sh  loadtest-reset.sh  sampler.sh  samples.jq  host-info.sh
              security-events.sh  k6-image.sh  repeat.sh  summarize.sh  lib.sh
  results/    git-ignored output; SCHEMA.md documents the one schema
```

## Environment and production safety

- `compose.loadtest.yaml` is an overlay used **only** by the loadtest targets (`LT` in the Makefile =
  `-f compose.yaml -f compose.loadtest.yaml`). It adds two optional env files to app, horizon, reverb and scheduler:
  `.env.loadtest` (yours, from `.env.loadtest.example`) and `.env.loadtest.variant` (written per run by
  `run-scenario.sh`: stock strategy, metrics on/off, multiplier unset). Order: `.env` < `.env.docker` <
  `.env.loadtest` < `.env.loadtest.variant`.
- Neither `compose.yaml` nor `compose.prod.yml` may reference those files: a stray `.env.loadtest` on a server changes
  nothing (`tests/Unit/LoadtestConfigTest.php`).
- Under `APP_ENV=production`: `/_bench/*` and `/_debug/*` are not registered (`BenchRoutesTest`, `DebugRoutesTest`),
  `loadtest:seed-users` refuses (`LoadtestSeedUsersTest`), `STOCK_STRATEGY=naive` and `STOCK_NAIVE_DELAY_MS` fail the
  boot (`StockStrategyBootTest`), `RATE_LIMIT_MULTIPLIER` fails the boot (`RateLimitMultiplierBootTest`, existing).
- The scripts refuse to run unless the **running app container** reports `APP_ENV` local or loadtest.
- k6 container: scripts mounted read-only at `/scripts`, `./loadtests/results` writable at `/results`, runs as
  `${UID:-1000}:${GID:-1000}` so the files are yours, `nofile` raised (`K6_NOFILE`, also `TRAEFIK_NOFILE`,
  `REVERB_NOFILE`), fixed `K6_CPUS` / `K6_MEM_LIMIT`. If the result file cannot be written, your uid is not 1000: put
  `UID=<id -u>` and `GID=<id -g>` into `.env.docker`.

## Scenarios

Parameters are make variables (`make k6-catalog RATE=80 DURATION=2m`). Defaults are load shapes, not results.

| Target | Variants | What it shows | Main parameters (default) |
|---|---|---|---|
| `make k6-catalog` | `VARIANT=naive\|optimized-cold\|optimized-warm\|all` | N+1 / OFFSET / missing indexes vs the optimized endpoint, same query set and page depth, `ramping-arrival-rate`; cold then warm cache. Records `catalog_products` | `RATE` (40/s), `WARMUP` (20s), `DURATION` (60s), `PAGE_DEPTH` (50) |
| `make k6-order-flood` | `STRATEGY=naive\|atomic\|locking\|all` | Many users buy the same low-stock product at one instant, with Idempotency-Key replays. atomic/locking: orders == stock exactly, the rest 409. naive: expected to oversell | `USERS` (150), `STOCK` (50), `REPLAY_PCT` (30), `NAIVE_DELAY_MS` (50) |
| `make k6-spike` | none | 10x jump, recovery, seconds back under the p95 budget, queue drain time, failed jobs | `RATE` (8/s), `SPIKE_FACTOR` (10), `SPIKE` (30s), `RECOVERY` (90s) |
| `make k6-soak` | none | Long moderate load with token refresh; resource sampling and slopes for leak detection | `DURATION` (30m; long run `DURATION=2h`), `RATE` (5/s) |
| `make k6-websocket-chat` | `MODE=ceiling\|fanout\|reconnect-jitter\|reconnect-immediate\|reconnect`, `REVERB_SCALE=1\|2` | Approximate per-replica connection ceiling, fan-out delivery latency (no clock skew), reconnect storm jitter vs immediate; `chat:verify-invariants --rebuild` after | `VUS`, `CONNS_PER_VU`, `CONVERSATIONS`, `FANOUT_SOCKETS`, `SOCKETS_PER_VU`, `STORM_TRIGGER` (server) |
| `make k6-auth-abuse` | none (multiplier unset) | 429 exactly at the configured limit, spoofed `X-Forwarded-For` ignored, lockout interplay, no 5xx, legitimate login from another IP, security log counts | `STUFFING_EMAILS` (60) |
| `make k6-metrics-overhead` | `METRICS=metrics-off\|metrics-on\|traces-on\|obs-up\|all` | The same load with metrics off/on and tracing on; series count and metrics-db memory | `RATE` (20/s), `DURATION` (3m) |

Things that will distort a run if ignored:

- **k6 is one client IP.** Per-IP limiters (`public-catalog` 300/min, `orders-ip` 120/min) are multiplied by
  `RATE_LIMIT_MULTIPLIER` (20 in the example = 100 catalog requests/s). Keep peak rates under that or raise the
  multiplier; `lt_http_429` in every result shows whether the limiter was hit. Only `auth-abuse` wants the limits.
- **`order-flood STRATEGY=all`** runs atomic, locking, then naive (naive last: its violations stay in the database and
  would fail the next verification). Each uses its own product and its own 150 users, so 500 seeded users are enough.
  The payment fake's failure/timeout rates are set to 0 for this scenario, otherwise released stock makes
  "orders == stock" inexact for reasons unrelated to the race.
- **`auth-abuse`** leaves accounts 401 to 493 locked or limited: `make loadtest-reset` afterwards.
- **`traces-on`** only sends traces with `SENTRY_LARAVEL_DSN` set; `run.env.SENTRY_DSN_SET` records it.
- **`obs-up`** is the one run with the observability stack up: resource cost and screenshots only, never latency.

## What a target does

`make k6-<scenario>` calls `scripts/run-scenario.sh <scenario> <variant>`:

1. writes `.env.loadtest.variant`, recreates app/horizon/reverb/scheduler when it changed, waits until healthy
2. refuses unless `APP_ENV` is local/loadtest, and unless the observability stack is down (`ALLOW_OBS=1` overrides
   and marks the result)
3. prepares the scenario (product and stock, cold cache = `FLUSHDB` of the cache db only, limits read from the app)
4. starts `sampler.sh` (every 10 s; soak 30 s; `SAMPLER=0` disables) and runs k6
5. `after-run.sh`: `queue:drain-time --json` where jobs were created, `orders:verify-invariants --json` always,
   `chat:verify-invariants --rebuild` for chat, failed jobs, a `/internal/metrics` snapshot taken inside the app
   container (works without Prometheus), sampler peaks and slopes, host info; all merged into the result JSON

Exit code: non-zero on any invariant violation, on a failed assertion or **baseline** threshold, or when k6 fails.
A missed **provisional** threshold is recorded and reported but does not fail the target. The only expected
violation is `order-flood`/`naive`: `after-run.sh --expect-violations` requires `violation_count > 0` there, and
refuses the flag for any other strategy.

Result files: `results/<scenario>-<variant>-<UTC>.json` (+ `.samples.ndjson`, `.metrics.prom`), schema in
`results/SCHEMA.md`. Copy curated ones to `docs/benchmarks/` with the same names (`docs/benchmarks/README.md`).

## Measurement hygiene

Scripted: `make k6-repeat TARGET=k6-<scenario> RUNS=3 COOLDOWN=60 [ARGS="STRATEGY=atomic"]` does reset, run,
cooldown three times and writes `summary-*.json` with median, min, max.

- `make loadtest-reset` before each run (`repeat.sh` does it; `RESET=0` only for read-only scenarios)
- `make obs-down` for every baseline
- fixed container `cpus` / `mem_limit` through `.env.docker` (`APP_CPUS`, `PG_CPUS`, `K6_CPUS`, ...); they are
  recorded in `host.compose_limits`
- AC power, no heavy background applications, nothing else using Docker
- the warm-up stage is tagged `phase=warmup` and excluded from every threshold
- each variant 3 times; report median, min and max; cooldown between runs
- record hardware and versions: `make loadtest-host-info` (CPU, RAM, OS, `.wslconfig`, Docker engine and version,
  compose limits, PHP / Postgres / Redis / PgBouncer versions). It is stored in every result under `host`

## Same machine or a separate load generator

**Same machine (default).** k6 runs as a compose service on the `edge` network and talks to `http://traefik`.
Simple and reproducible, but k6 competes with the system under test for CPU: with `K6_CPUS=2` on a 4-core laptop,
half the machine is the load generator. Watch `metrics.iterations.dropped`: if k6 drops iterations, the generator
(or `MAX_VUS`) is the bottleneck and the latency numbers describe k6, not the app. Lower the rate or give k6 more CPU.

**Separate machine.** Better isolation, adds network latency between the machines.

On the server (machine A):

```
# .env.docker: HTTP_BIND=0.0.0.0 (or A's LAN address). Trusted network only: /_bench and /_debug are exposed.
make loadtest-up && make loadtest-reset
PREPARE_ONLY=1 ./loadtests/scripts/run-scenario.sh catalog naive   # prepares A and prints the -e flags for k6
```

On the generator (machine B, with the repo checked out at the same commit and k6 v1.6.1 installed):

```
k6 run -e BASE_URL=http://<A>:8000 -e RESULTS_DIR=$PWD/loadtests/results <the -e flags printed on A> loadtests/scenarios/catalog.js
```

Copy the result JSON back into `loadtests/results/` on A and finish there:

```
./loadtests/scripts/after-run.sh --scenario catalog --variant naive --run-id <id from the file name> [--drain]
```

Notes: the app sees B's address as the client IP (still one IP for the limiters). WebSockets use the same host
(`ws://<A>:8000/app/...`); `ALLOWED_ORIGIN` must be in `CORS_ALLOWED_ORIGINS`. Clocks: `run.started_at` comes from B,
the sampler and `security_events_since` from A, so keep both on NTP if you correlate them. WebSocket delivery latency
is unaffected (sender and receiver are the same k6 process). The sampler does not run in this mode unless you start
`sampler.sh <file> 10` on A yourself. The `auth-abuse` cross-IP login is done by `run-scenario.sh` on A only.

## Which numbers are comparable

- **Comparable:** relative comparisons between variants run back to back, in one series, on one machine, with the
  same parameters: naive vs optimized, strategy A vs B, jitter vs immediate, metrics off vs on.
- **Not comparable:** absolute throughput or latency across machines, across different container limits, across
  different catalog sizes, or between a run with the observability stack up and one with it down.
- Container limits shape every number: a peak that sits at a `cpus` or `mem_limit` value measured the limit.
- Always publish `host`, `run.params` and `run.env` next to a number, and say it is a median of 3.

## WSL2

- **Which Docker engine?** Docker Desktop on Windows and an engine installed inside a WSL distribution are two
  separate engines with separate containers, images and volumes. `docker context show` and
  `make loadtest-host-info` (`docker.engine.operating_system`: "Docker Desktop" or the distro) tell you which one you
  are talking to. Seed and measure on the same one.
- **`.wslconfig`** (`%UserProfile%\.wslconfig`, then `wsl --shutdown`) caps the whole VM:
  ```
  [wsl2]
  memory=12GB
  processors=8
  swap=0
  ```
  Without it WSL takes 50% of host RAM and all cores, and container limits above that are fiction. The values are
  recorded in `host.wslconfig` (set `WSLCONFIG_PATH` if the file is not under `/mnt/c/Users/*/`).
- **`ulimit -n`** inside the distro (`host.host_ulimit_nofile`) and the container `nofile` limits both apply to the
  WebSocket ceiling test.
- **Host port conflicts:** `HTTP_PORT` (8000) may already be taken on Windows (another engine, IIS, a previous
  stack). `netstat -ano | findstr :8000` on Windows, `ss -ltnp | grep 8000` in WSL. Change `HTTP_PORT` rather than
  stopping something you do not recognise.
- Keep the repository inside the Linux filesystem (`~/...`), not under `/mnt/c`: bind mounts across the boundary are
  slow and would dominate every latency number.
- Power source cannot be read inside WSL (`host.power_source` says so): confirm AC power yourself.

## k6 image

- Pinned in `compose.yaml`: `${K6_IMAGE:-grafana/k6:1.6.1}` (through `REGISTRY_PREFIX` when a mirror is set).
- `make k6-image-check` prints the resolved image, whether it is pinned by tag or digest, its repo digest (copy it
  into `K6_IMAGE=grafana/k6@sha256:...` to pin by digest) and `k6 version`.
- **Offline fallback** when the mirror cannot serve the image: `make k6-image-fallback` downloads (or uses files you
  copied into `loadtests/results/.k6-download/`) the official release tarball and its checksums file from
  `https://github.com/grafana/k6/releases/download/v1.6.1/`, verifies the tarball against the published sha256
  (`sha256sum -c`), imports the binary as the local image `aghigh-k6:1.6.1-local`, and prints the two lines for
  `.env.docker` (`K6_IMAGE=aghigh-k6:1.6.1-local`, `K6_PULL_POLICY=never`). For reference, the checksums file
  published for v1.6.1 lists `k6-v1.6.1-linux-amd64.tar.gz` as
  `68df4958a1b089dc6f70a234e07c7ec818922f83b261ca24f3abf79882b13343` (fetched while writing this; the script checks
  against the checksums file itself, and `K6_SHA256=<value>` adds your own pin).

## Calibration after the first baseline

Until this is done every performance threshold is `provisional` (500 ms p95 and 1% errors are the numbers that
already existed in `smoke.js` and in the alert rule; 2000 ms chat delivery is from `chat-smoke.js`).

1. Hygiene checklist above, then for each scenario: `make k6-repeat TARGET=k6-<scenario> RUNS=3`.
2. Check each `summary-*.json`: `enough_runs`, `all_verdicts_passed`, `any_dirty: false`,
   `observability_stack: ["down"]`. Copy the files to `docs/benchmarks/`.
3. In `config/thresholds.json`, per scenario (or variant): set the budgets from the 3-run median with the headroom
   you decide and write down (a budget equal to the median fails half the time), set `"status": "baseline"`,
   `"baseline": {"date": "<UTC date>", "commit": "<git SHA of the runs>"}`. `tests/Unit/LoadtestConfigTest.php`
   rejects a baseline entry without date and commit.
4. Set the HTTP p95 alert budget in `docker/observability/prometheus/rules/aghigh.rules.yml`
   (`AghighHttpP95LatencyHigh`) from the soak p95, then `make obs-rules-test`.
5. Close the `TODO(measure)` items with values from the copied files: `docs/observability.md` (resource cost),
   `docs/queues.md` (Horizon memory and CPU), `docs/chat-protocol.md` (per-replica ceiling),
   `docs/performance-notes.md`, and fill `docs/benchmarks/capacity-handoff.md` for Task 11.
6. From then on a missed threshold fails the make target.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `login failed ... HTTP 429` in setup | `RATE_LIMIT_MULTIPLIER` is not applied: `make loadtest-config`, then `make loadtest-up` |
| `login failed ... HTTP 401` in setup | accounts missing or locked: `make loadtest-reset` |
| `_bench` route answers 404 | the app is not running with the overlay (`make loadtest-up`), or `APP_ENV` is not local/loadtest |
| k6 cannot write `/results/...json` | uid mismatch: set `UID` / `GID` in `.env.docker` |
| `the observability stack is up` | `make obs-down` (or `ALLOW_OBS=1` for a non-baseline run) |
| naive did not oversell | widen the window: `NAIVE_DELAY_MS=200`, more `USERS`, less `STOCK` |
| `ceiling was NOT reached` | raise `VUS` / `CONNS_PER_VU`; check `K6_NOFILE` and `K6_MEM_LIMIT` first |
| `dropped` iterations | k6 or `MAX_VUS` is the bottleneck: lower `RATE`, raise `MAX_VUS` or `K6_CPUS` |
