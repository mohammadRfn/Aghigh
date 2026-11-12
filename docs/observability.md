# Observability (Task 9): metrics, logs, errors, dashboards, alerts

Compose profile `observability`: Prometheus, Grafana, Loki, Grafana Alloy, cAdvisor, node_exporter,
postgres_exporter, pgbouncer_exporter, two redis_exporters, Traefik metrics, GlitchTip (web, worker, migrate).
Files: `docker/observability/`, targets `make obs-keys obs-up obs-down obs-rules-test obs-verify monitoring-role`.
UIs on 127.0.0.1 only: Grafana http://localhost:3000, Prometheus http://localhost:9090, GlitchTip
http://localhost:8090, Loki API http://localhost:3100. Nothing here was measured: every threshold, retention and
limit is `provisional` configuration; `TODO(measure)` marks what still has to be captured on a real run.

> Rule: run the stack SEPARATELY from latency measurements. `make obs-down` before a baseline; Task 10 measures
> the cost of app metrics themselves with `METRICS_ENABLED=true|false`.

**Status (2026-11-11, WSL2 + Docker Desktop): verified on a real host** with `make test-int` (61 passed),
`make verify-proxy`, `make k6 s=smoke` with the profile down, and `make obs-verify` (PASS, 6 rule groups, no unhealthy
rule). Also observed: `AghighTargetDown` fired after stopping `redis-exporter-cache` for 4 minutes, and GlitchTip
received both a manual `report()` and a failed Horizon job. Items still marked `UNTESTED` below were not exercised.

## First run
```
make obs-keys          # METRICS_TOKEN, Grafana admin password, GlitchTip + monitor secrets, Prometheus token file
make monitoring-role   # existing Postgres volume: monitoring role + GlitchTip db/role (new volumes get it from init)
docker compose --env-file .env.docker up -d --force-recreate app horizon reverb scheduler   # they read METRICS_TOKEN
make obs-up            # default stack + profile
make obs-rules-test    # promtool check/test rules + check config (pinned image)
make obs-verify        # provisioning, /internal denial, traffic burst, every query non-empty, inventory
```
GlitchTip first run (cannot be automated by compose, the DSN is minted by GlitchTip):
1. `docker compose --env-file .env.docker --profile observability exec glitchtip ./manage.py createsuperuser`
   (registration is disabled: `ENABLE_USER_REGISTRATION=False`).
2. Log in at http://localhost:8090, create an organization and a project (platform: Laravel / PHP).
3. Copy the project's DSN. It is printed with `GLITCHTIP_DOMAIN` (`http://<key>@localhost:8090/<id>`); the app
   talks to GlitchTip on the backend network, so put `SENTRY_LARAVEL_DSN=http://<key>@glitchtip:8000/<id>` into
   `.env.docker` and recreate app/horizon/reverb/scheduler. Empty DSN = the SDK is a no-op.
4. Test: `docker compose --env-file .env.docker exec app php artisan tinker --execute 'report(new RuntimeException("glitchtip smoke"));'`
   and look for the issue (tag `request_id` is absent for console reports, present for HTTP and queue ones).

## What runs where
| Service | Networks | Published | Why |
|---|---|---|---|
| prometheus, grafana, loki, glitchtip | edge + backend | 127.0.0.1 only | `backend` is `internal: true` (no published ports): a UI needs `edge` |
| alloy, cadvisor, node-exporter, *-exporter, glitchtip-worker/migrate | backend | none | exporters are scraped on `backend` |
| traefik metrics | entrypoint `metrics` :8082 | none | Prometheus (on `edge`) scrapes `traefik:8082`; target `traefik` is `up` with 376 series (2026-11-11) |

Every service: exact image tag (`*_IMAGE` env, `REGISTRY_PREFIX` honoured, `make pull` pulls the profile), `cpus` +
`mem_limit` env-tunable, `logging: *logging`, `restart: unless-stopped` (`glitchtip-migrate` is a one-shot,
`restart: "no"`). Telemetry/update checks off: Grafana env (`GF_ANALYTICS_*`, `GF_NEWS_NEWS_FEED_ENABLED`,
`GF_PLUGINS_PREINSTALL_DISABLED`, verified to exist in Grafana 12's `setting_plugins.go`), Loki
`analytics.reporting_enabled: false`, Alloy `--disable-reporting`, Traefik already had `checknewversion`/`sendanonymoususage` off.
Healthchecks only on Prometheus (busybox `wget`) and Grafana (`curl`), both `UNTESTED`; the other images were not
verified to carry a shell (redis_exporter `-alpine` does; the postgres/pgbouncer exporters and Loki were not checked)
so they have none, on purpose.

## App metrics (Phase 2)
- Contract `Modules\Shared\Contracts\Metrics`; catalog `Modules\Shared\Metrics\Catalog`; names `Metrics\Names`.
  Drivers: `redis` (promphp 2.15.1 Redis storage on the dedicated `metrics` connection, ADR 0010), `memory`
  (tests, strict), `null` (`METRICS_ENABLED=false`).
- `GET /internal/metrics`: bearer `METRICS_TOKEN` (constant time), empty token = 404, `Cache-Control: no-store`,
  `text/plain; version=0.0.4`, no limiter/session. Denied at Traefik by the `internal-deny` router (priority 100,
  `ipAllowList 192.0.2.1/32` -> 403): `make obs-verify` step 2 proves `curl localhost:8000/internal/metrics` is not
  200 with or without the token while the Prometheus container reads `app:8000/internal/metrics` with it.
- Metric list (one scheme, `aghigh_*`; HELP texts are in the Catalog and the collectors):

| Metric | Type | Labels | Source |
|---|---|---|---|
| `aghigh_http_requests_total` | counter | route, method, status_class | `RecordHttpMetrics::terminate()` |
| `aghigh_http_request_duration_seconds` | histogram 5 ms..10 s | route, method | same |
| `aghigh_queue_job_wait_seconds` | histogram | queue, job | `JobProcessing`: now - Horizon `pushedAt` (microtime, verified in horizon v5.50.1 `JobPayload::prepare()`), fallback Laravel `createdAt` (seconds); delayed jobs and retries skipped |
| `aghigh_queue_job_runtime_seconds` | histogram | queue, job | `JobProcessing` -> `JobProcessed`/`JobExceptionOccurred` |
| `aghigh_queue_jobs_processed_total` / `_failed_total` | counter | queue, job | `JobProcessed` / `JobFailed` |
| `aghigh_queue_{pending,delayed,reserved}_jobs`, `aghigh_queue_oldest_pending_age_seconds` (EXACT), `aghigh_queue_estimated_wait_seconds` (Horizon ESTIMATE) | gauge | queue | scrape time, `QueueMetrics::snapshot()` cached 5 s; null = sample omitted |
| `aghigh_queue_failed_jobs_stored`, `aghigh_queue_horizon_recent_failed_jobs` | gauge | | same |
| `aghigh_orders_total` | counter | outcome | `Modules\Ordering\Listeners\RecordOrderMetrics` (outbox events, at-least-once) |
| `aghigh_chat_messages_total`, `aghigh_chat_claims_total`, `aghigh_chat_closes_total` | counter | sender_role / closed_by_role | `Modules\Chat\Listeners\RecordChatMetrics` |
| `aghigh_rate_limit_hits_total` | counter | limiter, driver | `RateLimitHit` |
| `aghigh_state_store_errors_total` | counter | component | limiter (`RateLimitBackendFailed`), idempotency (`IdempotencyCache`), metrics (flushed); `chat_state` declared but never incremented: `RedisChatState` has no catch block, its errors are 5xx |
| `aghigh_metrics_write_failures_total`, `aghigh_metrics_backend_up`, `aghigh_metrics_series` | counter/gauge | driver | the pipeline itself |
| `reverb_pusher_api_connections`, `reverb_scrape_up` | gauge | | signed Pusher API call, 1 s timeout, cached 10 s |

### Reverb: what the connection number is (and is not)
Reverb 1.12.0 source, read for this task: `ConnectionsController` calls `MetricsHandler::gather($app, 'connections')`.
With `REVERB_SCALING_ENABLED` the handler publishes a metrics request over Redis pub/sub, waits (up to 10 s) for every
replica and MERGES the lists, so the number is global across replicas; without scaling it is the local server
only. The per-server source is `ChannelManager::connections()`: connections that joined at least one channel. That
matches the Task 8 observation (5 reported, 19 sockets ESTABLISHED, 5 subscribed). Reverb exposes nothing else
(no socket count, no per-replica breakdown) through the Pusher API. Per-replica sockets: cAdvisor's optional
`tcp` collector (`container_network_tcp_usage_total{tcp_state="established"}`, enabled in compose) if this host
produces it (see the inventory), otherwise `/proc/net/tcp` inside each replica (`make verify-chat` step 3).
Measured 2026-11-11: "reverb ESTABLISHED via cadvisor tcp" is empty on WSL2 (`docs/perf/obs-inventory.txt`), so use
the `/proc/net/tcp` fallback.

## Logs (Phase 3)
Alloy reads container logs of `app`, `horizon`, `reverb`, `scheduler`, `traefik` through the Docker socket
(`discovery.docker` + `loki.source.docker`). Why not the json-file path: on Docker Desktop/WSL2
`/var/lib/docker/containers` is inside the VM; a bind mount from WSL shows nothing. Cost: the socket is
root-equivalent even mounted `:ro` (the API can start privileged containers; `:ro` only protects the inode). Accepted
for a dev stack; the hardening path is a socket proxy (e.g. Tecnativa docker-socket-proxy with `CONTAINERS=1` only)
in front of Alloy, listed in Open decisions.

Labels: `service`, `channel`, `level` only (`max_streams_per_user: 1000`); `request_id` is STRUCTURED METADATA,
queried with `| request_id="..."`, never a label. Retention 168h, ingestion 4 MB/s burst 8 MB (provisional, env).
Traefik's access log keeps the response header: `--accesslog.fields.headers.names.X-Request-Id=keep`; in JSON the
response header field is `downstream_X-Request-Id` (verified on a real line).

### Two log sources (observed on Docker Desktop/WSL2)
Observed: JSON that the app workers write to `php://stdout` or `php://stderr` does not appear in `docker logs app`
(a failed login produced no line), while the Octane request lines do. Pointing the stream at `/proc/1/fd/1` made
it worse: the stream could not be opened ("No such file or directory") and Monolog's `UnexpectedValueException`
turned the request into a 500. Likely cause, NOT confirmed in the Octane source: `octane:start` runs FrankenPHP as a
child process and processes its output itself. Whatever the cause, the file route works: each service writes its
own JSON file (`LOG_JSON_STREAM=/app/storage/logs/<service>.jsonl`, set per service in `compose.yaml`;
`storage/logs` is a bind mount and git-ignored) and Alloy tails them (`local.file_match` + `loki.source.file`,
mounted read-only at `/var/log/aghigh`); the file name becomes the `service` label. The Docker socket source stays
for Traefik and for the plain lines (Octane request log, `schedule:work` output; they get `channel=stdout`,
`level=unknown`). `make test` and `make test-int` pass `LOG_JSON_STREAM=php://stdout` so test logs never reach the
files or Loki.

Known gaps (dev stack): the `*.jsonl` files grow without rotation (a later task); a log stream that cannot be
opened still fails the request (Monolog throws), which a production handler should swallow.

Log shape, verified on real lines: `request_id` lives in `extra`, and Traefik's access line carries
`downstream_X-Request-Id`. One id finds the Traefik line and the app lines (security, local) in one Loki query:

    curl -s -G localhost:3100/loki/api/v1/query_range --data-urlencode 'query={service=~"app|traefik"} |= "<request id>"' --data-urlencode since=10m

Observation: a plain 401 is logged at ERROR on the `local` channel (the default handler reports `IdentityProblem`),
so client errors add ERROR noise to Loki; Sentry already drops 4xx.

## Errors (Phase 3)
`sentry/sentry-laravel` 4.29 was already in composer.json; this task publishes `config/sentry.php` and wires
`ErrorReporting::register($exceptions)` (Sentry's `Integration::handles`) in `bootstrap/app.php` BEFORE
`ProblemDetails::render`, so it only reports, never renders. `SentryScrubber::beforeSend` (a static callable, not a
closure: `config:cache` works) drops client errors (`ProblemException` < 500, 4xx `HttpException`, validation/auth/
not-found) and scrubs with the shared `SensitiveKeys` list, tags `request_id` from `Context`. Job failures are
reported through the worker's `report()` path with the same hook. `send_default_pii=false`,
`traces_sample_rate` default 0.0 (capped at 0.05 in config), release from `GIT_SHA`.

### GlitchTip in Postgres-only mode (observed)
`GLITCHTIP_VALKEY_URL` empty means no Redis, and GlitchTip then keeps its cache in a database table. `migrate` does
not create it: the first login failed with HTTP 500 (`relation "django_cache" does not exist` in the django-allauth
rate limiter). The `glitchtip-migrate` step therefore also runs `createcachetable`. First-run order: `make obs-up`,
`createsuperuser`, create an Organization and a Project in the UI, then put the DSN in `.env.docker` with the
in-network host (`SENTRY_LARAVEL_DSN=http://<key>@glitchtip:8000/<project id>`; the UI shows `localhost:8090`) and
recreate `app horizon reverb scheduler`. Verified: `\Sentry\captureException(...)` followed by `flush()` from tinker
produced an issue in GlitchTip's database (`issue_events_issue`). A plain `report()` from tinker did not deliver
before the process exited (cause not confirmed; the HTTP and queue paths flush themselves).

## Following one request through metrics, logs and errors
Worked example (the ids below come from a dry run of the commands; replace with yours):
1. Make a request and keep its id: `curl -si localhost:8000/api/v1/shared/ping | grep -i x-request-id` ->
   `X-Request-Id: 0199c8a2-7b1e-7f6a-9c43-2d1f5e6a7b8c`.
2. Metrics: Grafana "Aghigh / API" shows the route `api.v1.shared.ping` in "Requests/s by route" and its latency in
   "p95 by route". Metrics never carry the id (bounded labels), they tell you WHEN and WHICH ROUTE.
3. Logs: paste the id into the dashboard variable `request_id` and click a latency/error panel -> data link
   "Loki: follow request_id" opens Explore with
   `{service=~"app|horizon|reverb|scheduler|traefik"} | request_id="0199c8a2-..."`: the Traefik access line
   (`channel="access"`), the app lines (`channel="app"|"security"|...`) and, if the request queued a job, the
   worker lines (Context propagates `request_id` into jobs).
   Loki API alternative: `curl -G localhost:3100/loki/api/v1/query_range --data-urlencode 'query={service=~"app|traefik"} | request_id="0199c8a2-..."'`.
4. Errors: if the request ended in a 5xx, GlitchTip has the issue with tag `request_id=0199c8a2-...`
   (search `request_id:0199c8a2-...` in the issue list).
`TODO(measure)`: replace the id above with a real one from `make obs-verify` output (step 5 prints a line).

## Dashboards (Phase 4): what each one answers
Provisioned from `docker/observability/grafana/dashboards/*.json` (folder "Aghigh", read-only in the UI):
- **API**: is the API healthy (5xx ratio, p95), which routes are slow or busy, Traefik codes/connections,
  limiter hits by name, state-store errors by component, the metrics pipeline's own health, app error logs.
- **DB**: PgBouncer pool busy and clients waiting (the capacity rule from docs/queues.md), Postgres backends vs
  `max_connections`, commits/rollbacks, tuples, cache hit ratio, deadlocks, locks by mode, size, temp spills,
  slow queries from Loki (`channel="slow_query"`).
- **Redis**: used vs maxmemory per INSTANCE (noeviction warning), keys per db (db0 queues, db2 state, db3 metrics),
  commands, clients, cache hit ratio, evictions, rejected connections, app state-store errors.
- **Queues**: pending/delayed/reserved by queue, oldest pending age (EXACT) and Horizon estimated wait (ESTIMATE)
  as separate panels, job wait p50/p95 (the Task 8 broadcast pickup delay), runtime by job class, throughput,
  failures, Horizon container CPU/memory from cAdvisor (may be empty on Docker Desktop).
- **WebSocket**: the connection panel states in its description that `reverb_pusher_api_connections` counts
  channel-subscribed connections per Reverb's Pusher API, merged across replicas when scaling, NOT sockets;
  `reverb_scrape_up`; broadcast wait/runtime; broadcasting-auth and chat limiter hits; chat messages/claims/closes;
  per-replica ESTABLISHED sockets from cAdvisor if present.
- **Orders**: outcomes (24h stats, rate, 1h totals), POST /orders latency and status classes (route
  `api.v1.ordering.orders.store`), orders limiters, idempotency cache errors, the orders queue (depth, wait, runtime,
  failures), payment webhook traffic.
Data links on latency/error panels open Loki filtered by the `request_id` dashboard variable, app errors, or
Traefik 5xx for the panel's time range.

Screenshots to add by hand after the first real run (placeholders, listed by name):
`docs/images/observability/grafana-api.png`, `grafana-db.png`, `grafana-redis.png`, `grafana-queues.png`,
`grafana-websocket.png`, `grafana-orders.png`, `glitchtip-issue.png`, `loki-request-trace.png`, `prometheus-alerts.png`.

## Alerts
No Alertmanager in dev: rules are evaluated by Prometheus and visible at http://localhost:9090/alerts and in
Grafana (Alerting -> Alert rules, Prometheus datasource). Nobody is notified; Task 11 decides on notification.
`make obs-rules-test` runs `promtool check rules`, `promtool test rules` (`aghigh.rules.test.yml`) and
`promtool check config` with the pinned image. Thresholds are provisional; each rule has `summary` + `runbook`
pointing to the anchors below (anchor = lower-cased alert name).

- **aghighhttp5xxratehigh** 5xx > 2% for 5m. Open API dashboard -> Traefik 5xx data link -> GlitchTip issue with the
  `request_id`. Common causes: PgBouncer saturation (DB dashboard), Redis state down (state-store errors).
- **aghighhttpp95latencyhigh** p95 > 500 ms for 10m. Check p95 by route, then DB (locks, pool waits) and Redis.
  Re-baseline the threshold after Task 10.
- **aghighmetricswritefailures** / **aghighmetricsbackenddown** the app cannot write/read redis-queue db 3:
  `docker compose --env-file .env.docker exec redis-queue redis-cli -n 3 ping`, memory (Redis dashboard), app log
  `metrics_write_failed` (once a minute per process).
- **aghighqueuedepthhigh** pending > 500 for 5m: `php artisan queue:metrics`, Horizon processes vs
  `HORIZON_*_MAX_PROCESSES` (docs/queues.md), failed jobs.
- **aghighqueueoldestpendingold** the head job is older than 60 s (exact): a stuck or starved queue; check workers
  are alive (`horizon:status`), runtime p95 of that queue's jobs.
- **aghighqueueestimatedwaithigh** Horizon's estimate > 60 s (info only; it is a projection, not a measurement).
- **aghighqueuejobsfailing** > 5 final failures in 15m for one job class: `php artisan queue:failed`, GlitchTip issue
  (job failures are reported with the same `request_id` tag when they had one).
- **aghighredismemoryhigh** / **aghighredisqueuenearmaxmemory** 80% / 95% of maxmemory. On redis-queue noeviction
  means write errors at 100%: find the growing db on the Redis dashboard (db0 backlog? db3 metrics cardinality ->
  `metrics:reset` and ADR 0010? db2 state?).
- **aghighstatestoreerrors** a component caught Redis errors: limiter fail modes (docs/rate-limiting.md),
  idempotency (database stays the truth), metrics (see above).
- **aghighpgbouncerpoolsaturated** / **aghighpgbouncerclientswaiting** the transaction pool is the bottleneck:
  docs/queues.md connection budget (`PGBOUNCER_POOL_SIZE` vs workers).
- **aghighpostgresconnectionssaturated** backends > 80% of `max_connections`: `make psql` ->
  `select usename, state, count(*) from pg_stat_activity group by 1,2;` (pool + migrator + exporters + GlitchTip).
- **aghighdiskusagehigh** root filesystem (of the Docker VM on Docker Desktop) > 85%: Prometheus/Loki retention,
  Postgres size, `docker system df`.
- **aghightargetdown** a scrape target is down for 3m: `make obs-ps`, `make obs-logs s=<service>`.
- **aghighreverbscrapedown** the signed Reverb call failed for 2m: Reverb replicas healthy? With scaling, the gather
  waits for every replica (10 s internally) while the app allows 1 s: a slow replica shows up here first.

## Resource cost of the stack
Measured 2026-11-11 on the WSL2 host, idle, right after `make obs-up` (not during load; the `make k6 s=smoke`
run is still to be captured). Command:
```
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' $(docker compose --env-file .env.docker --profile observability ps -q)
docker system df -v | grep -E 'prometheus-data|loki-data|grafana-data|glitchtip'
```
Idle result (observability profile only, `docker stats`, memory / limit):

| Service | CPU | Memory |
|---|---|---|
| grafana | 0.60% | 166.5 MiB / 384 MiB |
| glitchtip | 0.00% | 148 MiB / 512 MiB |
| alloy | 0.74% | 114.4 MiB / 256 MiB |
| prometheus | 3.16% | 104.9 MiB / 512 MiB |
| glitchtip-worker | 1.93% | 87.4 MiB / 512 MiB |
| loki | 1.04% | 77.7 MiB / 512 MiB |
| cadvisor | 0.00% | 21.4 MiB / 256 MiB |
| 5 exporters (node, postgres, pgbouncer, 2x redis) | 0.80% total | 8.6-12.7 MiB each / 64 MiB |

The whole profile is about 770 MiB and under 10% of one core at idle. No service is near its limit, so the
`*_CPUS` / `*_MEM_LIMIT` defaults stand for dev; re-check under load before raising or lowering them.

## WSL2 / Docker Desktop caveats (measured 2026-11-11 unless marked `UNTESTED`)
- **Measured result:** cAdvisor exposes only 76 series on this host and no `container_*` metrics, and
  `container_network_tcp_usage_total` (Reverb ESTABLISHED) is empty. Per-container CPU/memory therefore come from
  `docker stats` (see "Resource cost"), and per-replica sockets from `/proc/net/tcp` inside the replicas
  (`make verify-chat`). Do not build panels on `container_*` for this host.
- cAdvisor, original expectation: per-container series need the compose labels (`container_label_com_docker_compose_service`); on Docker
  Desktop the `/var/lib/docker` and `/dev/disk` mounts may be empty and filesystem series missing. The `tcp`
  collector is enabled for the Reverb socket question. `make obs-verify` writes the exact list of `container_*`
  names and the compose services it sees to `docs/perf/obs-inventory.txt`; copy that list here. If nothing shows,
  the documented fallback is `docker stats` (soak test) and `/proc/net/tcp` inside the reverb replicas.
- node_exporter reports the Docker Desktop VM (its CPU, memory, root disk), not Windows. It lists dozens of
  `/mnt/wsl/...` and `/run/...` mountpoints; only `mountpoint="/"` is meaningful for the disk alert. The disk alert therefore
  watches the VM disk, which is what Prometheus/Loki/Postgres volumes fill.
- Alloy: Docker socket source (see Logs). If `loki.source.docker` shows no targets, check the compose project label
  (`com.docker.compose.project=aghigh`, the compose `name:`).
- Bind-mounted secret file: `docker/observability/secrets/metrics_token` is created mode 0644 because the Prometheus
  process is `nobody` and bind mounts keep host permissions.
- pgbouncer_exporter logs in to the `pgbouncer` console as the APP user: the edoburu image's entrypoint (read for
  this task) writes only `DB_USER` to `userlist.txt`, in plaintext when `AUTH_TYPE=scram-sha-256` (PgBouncer
  derives SCRAM from it), and `STATS_USERS` maps 1:1 to `stats_users`. A dedicated stats role would need a custom
  auth file mounted over the generated one; not worth it in dev. `stats_users` grants `SHOW *` only. If the console
  login fails on the real host (`pgbouncer_up == 0`), fall back to Postgres-side saturation (`pg_stat_database`),
  which the DB dashboard also shows.

## Tests
- `make test` (Feature, Arch, Unit; `METRICS_DRIVER=memory`): `MetricsEndpointTest` (token missing/wrong/right,
  disabled endpoint, content type + no-store, unmatched label, 200 random paths keep the series count constant,
  `/up` and `/internal/metrics` skipped, null driver, Redis unreachable does not fail the request, `metrics:reset`
  refused in production, strict memory driver), `SentryScrubTest` (body/token/cookie/password never survive, 4xx
  dropped, 5xx kept, request_id tag, shared key list), `tests/Arch/ModulesTest.php` (vendor imports only in Shared,
  feature modules use only the contract).
- `make test-int`: `tests/Concurrency/MetricsCounterTest.php`: six real processes x 200 increments on the real
  metrics db 13 = exactly 1200.
- `make lint stan test test-int obs-rules-test` must be green before merge (`UNTESTED` by the author: no PHP here).
