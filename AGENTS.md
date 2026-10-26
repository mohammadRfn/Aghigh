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

## Workflow
One branch per task (`feat/<task>`), conventional commits. Before finishing: `make lint stan test test-int` green.
Write the docs a task asks for in `docs/`. Mark anything unmeasured as `TODO(measure)`.