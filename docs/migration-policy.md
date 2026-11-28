# Migration policy (Task 11)

A deploy runs migrations **before** the new code starts, while the previous release is still serving. A rollback
redeploys the previous image and **never** runs `migrate:rollback`. Both only work if every migration is
backward compatible with the release that is already running.

Enforced by `scripts/check-migrations.php` (CI job `migrations`, `make check-migrations`).

## Expand, migrate, contract

| Phase | Release | What ships | Old code keeps working because |
|---|---|---|---|
| Expand | N | new nullable columns, new tables, new indexes (concurrently), constraints `NOT VALID`; code that writes old and new | nothing it uses changed |
| Migrate | N or N+1 | batched backfill (job or command, never inside the migration transaction); code reads the new shape, still writes both | old columns are still there and still written |
| Contract | N+2 or later | drop the old column, table or constraint | no deployed or rollback-able release reads it any more |

Rules that follow:

- One release contains only expand-phase migrations together with the code that needs them.
- A column is dropped at the earliest **one release after** the last code that used it was removed, and only when
  that older release is no longer a rollback target (`/opt/aghigh/state/history` keeps five).
- Queued jobs follow the same rule: the deploy restarts Horizon before the web containers, so new workers must
  accept payloads written by the previous release for one release, and a new job class must not be dispatched by
  code that can run while old workers exist.
- `down()` is for development. Production never executes it, which is why the checker reports findings inside
  `down()` as notes instead of failures.

## Timeouts for every migration

The one-off `migrate` container sets libpq `PGOPTIONS` (`compose.prod.yml`):

```
-c lock_timeout=5s -c statement_timeout=15min        # MIGRATE_LOCK_TIMEOUT, MIGRATE_STATEMENT_TIMEOUT
```

`UNTESTED`: that PDO honours `PGOPTIONS` in this image was not run; check once with
`docker compose ... --profile tools run --rm migrate php artisan tinker --execute 'dump(DB::select("show lock_timeout"));'`
or set the same defaults on the role as a second line: `ALTER ROLE aghigh_migrator SET lock_timeout = '5s';`.

- `lock_timeout`: an `ALTER TABLE` that cannot get its lock within 5 s fails instead of queueing behind a long
  transaction and blocking every other query on that table. A failed migration aborts the deploy before any
  container changed; rerun it off-peak.
- `statement_timeout`: a migration that legitimately runs longer raises it **itself**, as its first statement:

```php
public $withinTransaction = false; // required for CONCURRENTLY

public function up(): void
{
    DB::statement("SET statement_timeout = '0'");       // session-level is fine: migrations connect directly, not through PgBouncer
    DB::statement("SET lock_timeout = '5s'");
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_user_created_idx ON orders (user_id, created_at)');
}
```

Inside a transactional migration use `SET LOCAL`.

## Indexes

- On an existing table of any size that matters: `CREATE INDEX CONCURRENTLY IF NOT EXISTS` in a migration with
  `$withinTransaction = false` (also in AGENTS.md). Example in the repository:
  `Modules/Catalog/database/migrations/2026_05_01_000003_add_catalog_query_indexes.php`.
- A cancelled or failed concurrent build leaves an `INVALID` index. Find and drop it before retrying:
  `SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid;` then `DROP INDEX CONCURRENTLY ...`.
- Unique constraint on an existing table: build the unique index concurrently, then
  `ALTER TABLE ... ADD CONSTRAINT ... UNIQUE USING INDEX ...`.
- Indexes on a table created in the same migration are fine (the table is empty).

## Foreign keys and check constraints

Modules reference each other by id without foreign keys (ADR 0001); inside a module:

```sql
ALTER TABLE order_items ADD CONSTRAINT order_items_order_fk FOREIGN KEY (order_id) REFERENCES orders (id) NOT VALID;  -- release N: short lock, no scan
ALTER TABLE order_items VALIDATE CONSTRAINT order_items_order_fk;                                                    -- later migration: scan without blocking writes
```

Same pattern for `CHECK` constraints on existing tables (`NOT VALID`, then `VALIDATE CONSTRAINT`), and for
`NOT NULL` on an existing column: add `CHECK (col IS NOT NULL) NOT VALID`, validate, then `SET NOT NULL`.

## Backfills

- Never in the schema migration's transaction. Use a queued job or an artisan command.
- Batches by primary key range (UUIDv7 ids are time-ordered), a short transaction per batch, a pause between
  batches, resumable (remember the last id), idempotent (`WHERE new_col IS NULL`).
- Through PgBouncer the usual rules apply: no session state, `pg_advisory_xact_lock` only.
- Batch size and pause are `TODO(measure)` per table: start small and watch `cl_waiting` (`SHOW POOLS`) and
  replication of the WAL archive (`select * from pg_stat_archiver`).

## Allowed and forbidden

| Change | In a normal release? | How |
|---|---|---|
| Add a table | yes | |
| Add a nullable column, or a column with a constant default | yes | catalog-only on PostgreSQL |
| Add an index on an existing table | yes | `CREATE INDEX CONCURRENTLY`, `$withinTransaction = false` |
| Add a foreign key or check on an existing table | yes | `NOT VALID`, validate in a later migration |
| Add `NOT NULL` to an existing column | yes | check constraint `NOT VALID` -> validate -> `SET NOT NULL` |
| Drop a column or table | **contract release only** | ignore marker with the release that stopped using it |
| Rename a column or table | **never in place** | add new, write both, backfill, switch reads, drop old later |
| `->change()` (type, length, nullability) | **no** | new column + backfill; may rewrite the table under `ACCESS EXCLUSIVE` |
| Non-concurrent index, validating FK, `VACUUM FULL`, `CLUSTER`, rewriting default on a big table | **no** | they hold locks for the duration of a scan or rewrite |
| `CREATE DATABASE`, roles, grants | **never in a migration** | `docker/postgres/init/` (AGENTS.md) |
| `migrate:rollback`, `migrate:fresh`, `db:wipe` in production | **never** | roll back by redeploying the previous SHA |

## The checker

```
php scripts/check-migrations.php            # CI; locally: make check-migrations
```

| Rule | Flags |
|---|---|
| `drop-column` | `dropColumn`, `DROP COLUMN` |
| `rename-column` | `renameColumn`, `RENAME COLUMN` |
| `rename-table` | `Schema::rename`, `RENAME TO` |
| `change-column` | `->change()` |
| `drop-table` | `Schema::drop*`, `DROP TABLE` in `up()` |
| `index-not-concurrent` | builder index or `CREATE INDEX` without `CONCURRENTLY` on a large table that the same file does not create |
| `fk-not-valid` | builder foreign key, or `ADD CONSTRAINT ... FOREIGN KEY` without `NOT VALID`, on such a table |

- "Large tables" is a list in the script (`DEFAULT_LARGE_TABLES`, override with `MIGRATION_LARGE_TABLES=a,b`). It is
  a judgement, not a measurement: `TODO(measure)` real row counts after launch
  (`select relname, n_live_tup from pg_stat_user_tables order by 2 desc`) and trim the list.
- It is a line-based pattern check, not a parser: multi-line statements and dynamically built SQL can escape it.
  Review still matters.
- Ignore marker, on the line or the line above, reason mandatory:
  `// migration-check: ignore[drop-column] contract phase, unused since release <sha>`
- Baseline: `scripts/check-migrations-baseline.txt` lists the twelve migrations that existed before the check.
  Result of the first run on them (2026-11-26): **one** finding,
  `Modules/Ordering/database/migrations/2026_05_20_000002_add_saga_columns_to_orders.php:23`, rule `drop-column`,
  and it sits in `down()` (the inverse of two added nullable columns), so it is a note, not a violation. `up()` of
  that file only adds nullable columns. New migrations are never added to the baseline.
