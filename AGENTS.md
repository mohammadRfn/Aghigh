# Aghigh: agent guide

Showcase e-commerce API built to be load-tested (races, N+1, queues, rate limiting, WebSocket chat).
Correctness under concurrency matters more than features. Never invent benchmark numbers.

## Stack
Laravel 13, PHP 8.5, FrankenPHP/Octane, PostgreSQL 18 behind PgBouncer (transaction mode),
two Redis instances, Horizon, Reverb, JWT (lcobucci/jwt + custom guard, built in Task 3), Pest 5, Larastan, Pint.
Everything runs in Docker: use `make up`, `make test`, `make lint`, `make stan`, `make test-int`.
Do NOT run `composer require laravel/boost` or `boost:install`.

## Modules (nwidart, `Modules/*`)
Shared (kernel), Identity, Catalog, Inventory, Ordering, Payment, Chat, Notification.
- A module may use another module only through its Contracts, Events and DTOs.
  Never import another module's Models, Http, Actions, Jobs, Listeners, Policies, Providers, Exceptions.
  `tests/Arch/ModulesTest.php` enforces this. Shared must not depend on feature modules.
- Reference other modules' data by id only (uuid column, indexed, no cross-module foreign key).
- All API routes live in the module's `routes/api.php`, served under `/api/v1`.
- `declare(strict_types=1)` in every PHP file.

## HTTP and errors
- Errors are RFC 9457 problem+json (`Modules\Shared\Exceptions\ProblemDetails`).
  Domain exceptions implement `Modules\Shared\Contracts\ProblemException`. Never leak internals.
- Every request has an `X-Request-Id`; it is in the log context and propagates to queued jobs.
- Money: integer minor units + ISO currency code, never floats. IDs: UUIDv7. See docs/adr/.

## Rate limiting (Task 7)
- Every limiter is named in `config/rate-limits.php` (env-tunable; values are configuration, not
  measurements) and attached with `throttle.sliding:{name}`; the per-user concurrency cap is
  `concurrency.cap:{name}`. Do not use `throttle:` or `RateLimiter::for()` in modules; do not read
  `X-Forwarded-For` (client IP = `Request::ip()` through TrustProxies, default = compose edge network).
- Counters live on the `state` Redis. Only `Modules\Shared\RateLimiting` touches limiter keys/Lua
  (`tests/Arch/RateLimitingBoundaryTest.php`). Drivers: `laravel` (fixed window) for most, `sliding`
  (Lua sliding log, bounded by `sliding_max_limit`) only for `orders`. Fail mode per limiter: `open`
  (catalog, webhook) or `closed` (auth, orders, admin -> 503 `rate-limit-unavailable`).
- Middleware order on protected routes: pre-auth per-IP limiter -> `auth:api` -> per-user limiter ->
  concurrency cap -> idempotency. A 429 is thrown, so it never stores an Idempotency-Key.
- 429 = problem+json `too-many-requests` with `Retry-After` + `RateLimit-Limit/Remaining/Reset`
  (also on 2xx; most restrictive limiter wins; no `X-RateLimit-*`). Every hit logs `rate_limit_hit` on
  `security` (hashed key, sampled above N/window) and dispatches `Shared\Events\RateLimitHit`.
- Business limits (e.g. orders per minute per user, `OrderRateBudget`) live in the action after the
  idempotency replay check, counted in Redis outside the transaction; 409 attempts consume budget.
- `RATE_LIMIT_MULTIPLIER` scales limits only in local/testing/loadtest (boot fails elsewhere). Feature
  tests run the in-memory limiter (phpunit.xml, multiplier 1000); limiter tests pin values with
  `Tests\Support\RateLimitTesting::exact()`. Anything Lua/Redis goes in the Concurrency suite.
- Docs: `docs/rate-limiting.md`, ADR 0008. Lockout (identity.lockout.*) is a separate mechanism.
- Chat (Task 8): `chat-messages` (closed) on POST messages, `chat-claim` (closed), `chat-typing`, `chat-heartbeat`,
  `chat-read` (open), all after `auth:api`. The approximate per-user socket cap is
  `rate-limits.concurrency.chat-connections` (read by Chat's ConnectionCap at channel auth, multiplier applies).

## Redis roles (important)
- `cache` connection (redis-cache, allkeys-lru): disposable data only (e.g. catalog cache).
- `default` connection (redis-queue, noeviction, AOF): queues, Horizon, Reverb pub/sub.
- `state` connection (redis-queue db 2, noeviction): JWT denylist, rate limiter, idempotency fast-path,
  presence, unread counters, support queue, locks. Use `Cache::store('state')`.
  Never put correctness-critical data in the `cache` connection.

## Postgres
- App connects through PgBouncer (transaction pooling): no session-level advisory locks, no LISTEN/NOTIFY,
  no `SET` without `LOCAL`. Use `pg_advisory_xact_lock` and keep transactions short.
- Migrations run as the migrator role directly against Postgres (`make migrate`).
- Indexes on big tables: `CREATE INDEX CONCURRENTLY` in a migration with `$withinTransaction = false`.

## Logging
JSON on stdout, channels `app`, `security`, `audit`, `slow_query`. Sensitive keys are redacted by
`RedactSensitiveData`, but still never log secrets, tokens or full request bodies.

## Tests
- Default suites (Unit, Feature, Arch) run on SQLite in memory: `make test`.
- Anything about races, locks, Lua or real Redis goes in `tests/Concurrency` or
  `Modules/*/tests/Concurrency` and runs with `make test-int` (Postgres `aghigh_test` + Redis).
  Prove races with real parallel processes, not sequential calls.

## Environment (read before running anything)
- Everything runs in Docker. Use `make shell` or `docker compose --env-file .env.docker exec app <cmd>`.
  Never run php, artisan or composer on the host: the host `.env` still points at sqlite.
- Env layering: `.env` < `.env.docker` < compose `environment:` (DB_* are forced there to PgBouncer + the app role).
  Add every new setting to BOTH `.env.example` and `.env.docker.example`, and tell the user to copy it into
  their git-ignored `.env.docker`.
- Postgres: database `aghigh` and two roles are created once by `docker/postgres/init/01-roles.sh` (empty volume only).
  `aghigh_migrator` owns the database and runs migrations; `aghigh_app` (used through PgBouncer) only has DML on
  tables the migrator creates (default privileges). So migrate with `make migrate` (dev reset: `make fresh-seed`),
  never with plain `artisan migrate` inside the container. Never put CREATE DATABASE or role logic in migrations.
  Trusted extensions such as `pg_trgm` and `citext` may be created by the migrator inside a migration.
- Test database `aghigh_test` is created by `make test-db`; the Concurrency suite connects to it directly
  (not through PgBouncer) as the migrator role.
- Octane has no file watcher: after code or config changes run
  `docker compose --env-file .env.docker exec app php artisan octane:reload`; after queue code changes run
  `php artisan horizon:terminate` (the container restarts itself).
- Docker-level changes go in `compose.yaml` + `.env.docker.example`, then `make up`.
- Mail goes to Mailpit (UI http://mail.localhost:8000). Queues and cache use Redis; sessions are not used.

## Queues, Horizon and scheduler (current state; Task 6 reworks it)
- Queue connection `redis` (redis-queue, db 0, noeviction). Containers: `horizon` (`php artisan horizon`,
  stop grace 30s) and `scheduler` (`schedule:work`).
- Horizon today has ONE supervisor that listens only on the queue `default` (tries 1, timeout 60s, 3 processes in
  local). Until Task 6 adds the queues `orders`, `broadcasts` and `notifications`, dispatch everything on
  `default`: a job on any other queue silently never runs.
- `retry_after` is 90s (REDIS_QUEUE_RETRY_AFTER): every job `timeout` must stay below it.
- Jobs carry IDs only (never models or secrets), are idempotent, set tries/backoff explicitly, define `failed()`,
  and use `afterCommit()` when dispatched inside a transaction (the queue config has after_commit=false).
  `request_id` propagates into jobs through Context.
- Scheduled work goes in `routes/console.php` with `->onOneServer()->withoutOverlapping()`.
- In tests the queue is `sync`; fake Mail/Queue/Event unless the test is about the queue itself.

## WebSockets (Reverb) (Task 8)
- Container `reverb` (`reverb:start`, port 8080, scalable: `REVERB_SCALE=2 make up`, no container_name/host ports).
  Traefik (host port 8000) routes only `/app/` to Reverb; `/apps/` (signed HTTP API) is NOT routed.
- Two addresses, never mixed: the app/horizon PUBLISH to `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`
  (config/broadcasting.php + reverb apps[].options); clients use `VITE_REVERB_HOST=localhost`, `VITE_REVERB_PORT=8000`,
  `VITE_REVERB_SCHEME=http`, path `/app`. Keys: `make reverb-keys` (dev, fills empty values in .env.docker).
- Channel auth = the single Task 7 route `POST /api/broadcasting/auth` (`api, auth:api, throttle.sliding:broadcasting-auth`).
  Channels are declared by the owning module (`Modules/Chat/routes/channels.php`, `guards => ['api']`); root
  routes/channels.php only points there. Never cast user ids to int.
- `allowed_origins` = hosts (Reverb compares the Origin HOST only) from `REVERB_ALLOWED_ORIGINS`, default hosts of
  `CORS_ALLOWED_ORIGINS`; `accept_client_events_from=none`. `max_connections` is per Reverb instance.
  `REVERB_MAX_REQUEST_SIZE` / `REVERB_APP_MAX_MESSAGE_SIZE` = 32768 (Persian size arithmetic in docs/chat-protocol.md).
- Scaling pub/sub reads `REDIS_HOST`/`REDIS_DB` (redis-queue db 0), not the `state` connection.
- REST is the truth; broadcasts carry ids, load the committed row in `broadcastWith()`, implement
  `ShouldDispatchAfterCommit`, run on `Queues::BROADCASTS` with explicit tries/backoff/timeout (< 30 s). Best effort:
  clients gap-fill with `?after_seq=` and order by seq. Protocol, reconnect algorithm, limits: docs/chat-protocol.md.
- Chat Redis state only through `Modules\Chat\State\ChatState` (`state` connection, `chat:` prefix; Feature tests use
  `CHAT_STATE_DRIVER=memory`). Invariants: `php artisan chat:verify-invariants --rebuild`. ADR 0009.

## Observability and metrics (Task 9)
- Record metrics ONLY through `Modules\Shared\Contracts\Metrics` (`counter`, `gauge`, `histogram`). Every metric is
  declared once in `Modules\Shared\Metrics\Catalog` (type, HELP, label names, buckets) and named by a constant in
  `Modules\Shared\Metrics\Names`. Only `Modules\Shared` may import `Prometheus\`, `Sentry\` or `Pusher\`
  (tests/Arch/ModulesTest.php). Order and chat listeners live in the OWNING module (`Modules/Ordering/app/Listeners`,
  `Modules/Chat/app/Listeners`) and use only the contract.
- Cardinality rules (ADR 0010): label values come from a BOUNDED set: route name or URI template, method, status
  class (`2xx`..`5xx`), queue, job class (short name), limiter name, outcome, role. Never ids, emails, IPs, raw URLs,
  request ids. Unmatched routes are `route="unmatched"`. A new label needs a Catalog change and a reason in the ADR.
- Drivers (`METRICS_DRIVER`): `redis` (default; promphp Redis storage on the DEDICATED `metrics` Redis connection =
  redis-queue db `REDIS_METRICS_DB` 3, never `state`), `memory` (tests; also strict: unknown metric/label throws),
  `null` (also selected by `METRICS_ENABLED=false`). Writes never throw and never block: failures are counted in
  `Metrics\Failures` and logged once a minute. Never keep metric state in PHP memory (2 Octane workers, max-requests 500).
- HTTP metrics are recorded in `RecordHttpMetrics::terminate()`; queue wait/runtime from the worker events; queue
  depth, oldest age (exact) and Horizon wait (estimate) are computed at scrape time from `QueueMetrics::snapshot()`
  (cached 5 s). `null` from a source = omit the sample, never 0.
- How to add a metric: constant in `Names` -> definition in `Catalog` -> call through the contract in the owning
  module -> panel in `docker/observability/grafana/dashboards/*.json` -> (if alert) rule + promtool test ->
  `make obs-rules-test obs-verify`. Then document it in docs/observability.md.
- `/internal/metrics`: bearer `METRICS_TOKEN` (empty = 404), no limiter/session, denied at Traefik
  (`internal-deny` router). Env: `METRICS_ENABLED`, `METRICS_DRIVER`, `METRICS_TOKEN`, `REDIS_METRICS_DB`,
  `METRICS_QUEUE_CACHE_SECONDS`, `METRICS_REVERB_CACHE_SECONDS`, `METRICS_REVERB_TIMEOUT_SECONDS`, `SENTRY_LARAVEL_DSN`,
  `SENTRY_TRACES_SAMPLE_RATE`, `GIT_SHA`. `make test` and CI pass `METRICS_DRIVER=memory`; `make test-int` uses the
  real metrics db 13. `php artisan metrics:reset` (refused in production) wipes the metrics db.
- Errors: `SentryScrubber::beforeSend` (5xx + job failures only, scrubbed with `Logging\SensitiveKeys`, the same list
  as `RedactSensitiveData`). Never put a closure in config/sentry.php. Stack: `make obs-up`, `make obs-verify`,
  `make obs-down` before any latency baseline (docs/observability.md).

## Workflow
One branch per task (`feat/<task>`), conventional commits. Before finishing: `make lint stan test test-int` green.
Write the docs a task asks for in `docs/`. Mark anything unmeasured as `TODO(measure)`.
Number new ADRs with the next free number in docs/adr/. Cross-module calls go through `Contracts/` interfaces;
extend `tests/Arch/ModulesTest.php` when you add one. Race conditions are proven with real parallel processes
against `aghigh_test` (helpers in `tests/Support/`), never with sequential calls.
At the end of every task print three sections: Summary, Open decisions (choices you made that the task left open),
and Manual steps for the user (env lines to copy into `.env.docker`, make targets to run, containers to restart).