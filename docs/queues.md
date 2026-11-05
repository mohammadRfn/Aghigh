# Queues, Horizon, scheduler and capacity (Task 6)

All numbers below are configuration, not measurements. Anything unmeasured is `TODO(measure)`.

## Topology
| Queue | What runs there | Horizon supervisor (min/max, default) | Supervisor timeout | maxJobs / maxTime |
|---|---|---|---|---|
| `orders` | saga: ChargePayment, ConfirmOrder, NotifyOrderConfirmed, CompensateOrder, RefundPayment | 1 / 4 | 60 s | 1000 / 3600 s |
| `broadcasts` | Reverb broadcasts (Task 8) | 1 / 2 | 30 s | 2000 / 3600 s |
| `notifications` | SendOrderConfirmation, SendIdentityMail | 1 / 2 | 60 s | 500 / 3600 s |
| `default` | ExpireReservations, RelayOutbox | 1 / 2 | 100 s | 500 / 3600 s |

Every supervisor: `balance: auto` (time strategy), `HORIZON_<QUEUE>_MIN_PROCESSES` / `_MAX_PROCESSES` /
`_MAX_JOBS` / `_MAX_TIME`, `memory` = `HORIZON_WORKER_MEMORY` (64 MB), default `tries` 3 and `backoff`
[10, 30, 90] for jobs that declare nothing. All four supervisors run in every environment (`'*'` included).

## Jobs
| Job | Queue | timeout | tries | maxExceptions | backoff (defaults) | Unique | Overlap lock (state Redis) |
|---|---|---|---|---|---|---|---|
| ChargePayment | orders | 30 | 10 | 3 | 5,15,45,120... | - | `ordering:order:{id}` shared + gateway throttle |
| ConfirmOrder | orders | 30 | 5 | 3 | 5,15,45,120 | - | `ordering:order:{id}` shared |
| NotifyOrderConfirmed | orders | 30 | 5 | 3 | 5,15,45,120 | - | `ordering:order:{id}` shared |
| CompensateOrder | orders | 30 | 10 | 5 | 5,15,45,120... | order id, 300 s | `ordering:order:{id}` shared |
| RefundPayment | orders | 30 | 10 | 3 | 5,15,45,120... | order id, 600 s | `ordering:refund:{id}` + gateway throttle |
| SendOrderConfirmation | notifications | 30 | 5 | 3 | 10,30,90,270 | order id, 3600 s | `ordering:mail:{id}` |
| SendIdentityMail | notifications | 30 | 4 | 3 | 5,30,90 | - | state lock `identity:mail:{id}` |
| ExpireReservations | default | 90 | 3 | 3 | 5,15 | yes, 120 s | `ordering:expiry-sweep` |
| RelayOutbox | default | 90 | 3 | 3 | 5,15 | yes, 120 s | `shared:outbox-relay` |

- `retry_after` = 120 s (`REDIS_QUEUE_RETRY_AFTER`) > every timeout above (max 100 s).
  `tests/Feature/QueueTopologyTest.php` scans every queued class and supervisor and fails otherwise.
- Releases by the overlap lock (5 s) or the gateway throttle count toward `tries`, not `maxExceptions`.
- `WithoutOverlappingOnState` replaces Laravel's `WithoutOverlapping`, which always locks on the default
  cache store (here the LRU `cache` Redis, where a lock may be evicted). `ShouldBeUnique::uniqueVia()` uses
  the same store (`queue.locks.store` = `state`).
- `ThrottlesExceptionsWithBackoff` = `ThrottlesExceptions` keyed `ordering:payment-gateway` (20 transient errors /
  60 s open the circuit) whose per-error delay follows the job's backoff in seconds (upstream uses minutes).
- `request_id` travels in the payload (`illuminate:log:context`) and is restored in the worker
  (asserted in `Modules/Shared/tests/Feature/QueueInfrastructureTest.php`).

## Database connections vs PgBouncer
Every PHP process holds at most one client connection; under transaction pooling it uses a server connection
only while a statement or transaction runs. Worst case = every process inside a transaction at once:

| Process | Default count | Source | Max DB clients |
|---|---|---|---|
| Octane (FrankenPHP) workers | 2 | compose `--workers=${OCTANE_WORKERS:-2}` | 2 |
| Horizon `orders` workers | 4 | `HORIZON_ORDERS_MAX_PROCESSES` | 4 |
| Horizon `broadcasts` workers | 2 | `HORIZON_BROADCASTS_MAX_PROCESSES` | 2 |
| Horizon `notifications` workers | 2 | `HORIZON_NOTIFICATIONS_MAX_PROCESSES` | 2 |
| Horizon `default` workers | 2 | `HORIZON_DEFAULT_MAX_PROCESSES` | 2 |
| Horizon master + 4 supervisors | 5 | no queries | 0 |
| Reverb | 1 | counted although it does not query today | 1 |
| Scheduler | `schedule:run` + 1 scheduled command | jobs are only pushed | 2 |
| **Total** | | | **15** |
| PgBouncer `DEFAULT_POOL_SIZE` | | compose `${PGBOUNCER_POOL_SIZE:-20}` | **20 (5 spare)** |

Rule: total < pool. Raising `OCTANE_WORKERS` or any `HORIZON_*_MAX_PROCESSES` means raising
`PGBOUNCER_POOL_SIZE` too (and Postgres `max_connections`, default 100, must stay above pool + migrator + tests).
The capacity test parses compose.yaml and the Horizon config and fails when the rule breaks.
Migrations and the Concurrency suite connect to Postgres directly and are not counted.

## Horizon container budget
compose: `cpus: ${HORIZON_CPUS:-1.5}`, `mem_limit: ${HORIZON_MEM_LIMIT:-1280m}`, `stop_grace_period: 110s`
(> the 100 s longest timeout, so a deploy lets running jobs finish).

| Item | Count | MB each (restart threshold) | MB |
|---|---|---|---|
| Workers (sum of maxProcesses) | 10 | 64 | 640 |
| Supervisor processes | 4 | 64 | 256 |
| Master | 1 | 64 (`memory_limit`) | 64 |
| Headroom (PHP binary, opcache SHM) | | | 128 |
| **Total** | | | **1088 <= 1280** |

`memory` is PHP heap (`memory_get_usage`), not RSS. TODO(measure): RSS per worker with `docker stats` under
the Task 10 load, then tighten `HORIZON_MEM_LIMIT` or `HORIZON_WORKER_MEMORY`. TODO(measure): CPU need.

## Scheduler
`routes/console.php`, every entry `->onOneServer()->withoutOverlapping()`. Mutexes live on
`cache.schedule_store` (= `SCHEDULE_CACHE_DRIVER`, default `state`): Laravel 13's console kernel reads
`config('cache.schedule_store', env('SCHEDULE_CACHE_DRIVER', env('SCHEDULE_CACHE_STORE')))`.
| Entry | Frequency | Effect |
|---|---|---|
| `ordering:expire-reservations` (job) | every minute | reserved -> expired + stock back, batches of 500, SKIP LOCKED |
| `shared:outbox-relay` (job) | every 10 s | publish due outbox rows (lease + SKIP LOCKED), at-least-once |
| `identity:prune-tokens` (command) | daily | Task 3 token pruning |
For job entries the scheduler mutex only covers the push; `ShouldBeUnique` + the overlap lock keep runs apart.

## Metrics and operations
- `php artisan queue:metrics [--json] [--connection=] [--queue=*]`: per queue pending / delayed / reserved / total,
  `oldest_pending_age_seconds` (head job `createdAt`), `estimated_wait_seconds` (Horizon `WaitTimeCalculator`,
  an estimate from Horizon's metrics snapshots), `failed.stored` (failed_jobs) and `failed.horizon_recent`.
  Service: `Modules\Shared\Queue\QueueMetrics` (scraped in Task 9).
- `php artisan queue:drain-time [--timeout=900] [--interval=0.5] [--json]`: polls until pending + delayed + reserved
  is 0 on all queues, prints the elapsed seconds (exit 1 on timeout). Task 10 runs it after a burst.
- `php artisan orders:verify-invariants [--json]`: orders vs reservations vs stock counters vs payments.
  Run it on a drained system.
