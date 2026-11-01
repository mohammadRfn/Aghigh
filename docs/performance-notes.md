# Catalog: a deliberate, reproducible performance comparison

## Evidence status

No Laravel/PHP/Docker/PostgreSQL-18 benchmark was run in the delivery environment. All performance results are **TODO(measure)**. Defaults, seed distributions, query-count expectations and test load are configuration or structural assertions, not observations. Do not convert this document into a speedup claim until raw output is committed with its conditions.

## Setup and role separation

1. Apply the package to upstream b634d7706fe2e3eb3ae23d347444d6a26218f269 on `feat/catalog`. Task 3 roles and JWT setup must already work.
2. Copy all Catalog env values from the README into `.env.docker`; set a real image base URL. `make up` recreates containers with env changes. Run Composer autoload generation inside the app container.
3. **Development reset only:** `make fresh-seed`. This destroys local database tables. The command supplies DB_HOST=postgres and migrator credentials exactly as required by AGENTS.md.
4. `make seed-catalog N=100000`. To test scale, use N=1000000 on a freshly reset or explicitly truncated development dataset. No timing promise is made at either size.
5. `make psql` opens psql in the Postgres container as the migrator, directly against Postgres. Check `SELECT current_user, version();` and run `ANALYZE categories, products, product_images;`.
6. Do not run benchmarks against the app role through PgBouncer. The bulk command verifies TRUNCATE privileges on all three tables and rejects a configured PgBouncer host, SQLite, Postgres <18 and APP_ENV=production. Without --truncate, any nonempty catalog table aborts. It does not create roles/databases.

Explicit replacement:

`docker compose --env-file .env.docker exec -e DB_HOST=postgres -e DB_USERNAME=<migrator-user> -e DB_PASSWORD=<migrator-password> app php artisan catalog:seed-bulk --products=100000 --truncate`

Replace placeholders privately; do not put credentials in committed evidence. Prefer the Make target for initial seed because it passes existing Make env values without printing the command. There is intentionally no implicit truncate in `make seed-catalog`.

## Before and after the index migration

Start from the fresh-seed sequence above so the index migration is in the last applied batch. Pause other catalog writes and benchmark traffic. The base schema/PK/unique/FK constraints remain in place.

- Roll back ONLY the later index migration, as migrator/direct Postgres:
  `docker compose --env-file .env.docker exec -e DB_HOST=postgres -e DB_USERNAME=<migrator-user> -e DB_PASSWORD=<migrator-password> app php artisan migrate:rollback --path=Modules/Catalog/database/migrations/2026_05_01_000003_add_catalog_query_indexes.php --force`
- Check `SELECT indexname FROM pg_indexes WHERE tablename IN ('products','product_images');`. The five catalog query indexes must be absent; PK/unique indexes remain. If later migrations have since been applied, do not blindly rollback unrelated batches: use a clean benchmark worktree/database and restart this procedure.
- `make explain-catalog LABEL=before` executes `docs/perf/query-shapes.sql`, captures the actual EXPLAIN (ANALYZE, BUFFERS) output in `docs/perf/before.txt`, and adds machine/UTC/date/version/row-count/settings context. No placeholder output file is prefilled.
- `make migrate` recreates query indexes concurrently (outside a transaction). It does not drop data or recreate base tables. The migration retries invalid concurrent index builds by dropping only the invalid index first.
- `make explain-catalog LABEL=after` saves the second raw plan set. ANALYZE is run again by the SQL script.
- Label both files with CPU/RAM/storage/container resource limits as well; `uname` alone is not a hardware description. Record warm/cold buffer conditions, row count, image count, PostgreSQL version/settings, date and git commit. Repeat each run, do not cherry-pick a single winner.
- Keep the app cache out of SQL comparisons. If measuring HTTP separately, distinguish DB cold/warm, payload cold/fresh/stale, q cache bypass, network latency and framework overhead. The workspace's Next.js preview is NOT a Laravel benchmark.

### Result table (not yet measured)

| Shape | Before query indexes | After query indexes | Actual index/scan | Buffers/rows | Write/size cost |
|---|---|---|---|---|---|
| category + newest | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |
| price ascending | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |
| price descending | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |
| category + price | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |
| Persian trigram | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |
| primary image lookup | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |
| naive newest / OFFSET | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |
| naive ILIKE / price | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) | TODO(measure) |

## Query shapes and index choices

All optimized queries contain literal `status = 'active'`, not a parameterized or transformed predicate. This exposes the partial-index predicate to PostgreSQL even with generic prepared plans.

- `(category_id, id) WHERE status='active'`: category equality first, UUIDv7 cursor boundary/order second; backward scan for newest. A separate newest index is unnecessary because the UUID primary key already orders IDs (inactive rows may still be filtered on unscoped newest queries).
- `(price_minor, id) WHERE status='active'`: ascending or backward descending scan, deterministic id tie-break, direct tuple `(price_minor,id) >/< (?,?)` cursor boundary. No OFFSET. Composite cursor prevents duplicate-priced rows being skipped.
- `(category_id, price_minor, id) WHERE status='active'`: category equality followed by price order and UUID tie-break. It is a candidate, not an automatically proven win. The real plan must show whether it is chosen over category-only + sort. It adds another B-tree maintained on inserts, category moves, active-status changes and price updates; more WAL, write work and storage. Write amplification and actual index usage: TODO(measure). Drop it only after recording workload evidence and revising tests/docs.
- Partial GIN `lower(name) gin_trgm_ops WHERE status='active'`: exact expression matches `lower(name) LIKE ? ESCAPE '\'`. q is lowercased and literal `%`, `_`, `\` are escaped. pg_trgm handles Persian Unicode text; PostgreSQL tsvector full-text search would need an appropriate language/dictionary configuration and is not a substitute for substring semantics. GIN costs storage, insert/update maintenance and pending-list/vacuum work. Measure index size and writes. The current partial candidate has NOT been proven against the target planner. If a representative plan does not use it, investigate selectivity/statistics and compare a full GIN index; record the choice in a later migration rather than silently forcing an index.
- `(product_id, position)` on images: equality on parent then smallest position. ofMany supplies a deterministic UUID tie-break; PostgreSQL aggregates canonical UUID text and casts back because min(uuid) is not built in. Query plans for the actual generated eager relation should also be saved during target review.

### What “no indexes” means for the naive implementation

The tables are shared. Integrity indexes and all optimized indexes still physically exist. “Unindexed baseline” means its **query shapes cannot exploit the intended optimized indexes**: `SELECT *`, `(status || '')='active'` (not the partial predicate), category cast to text, `(price_minor + 0)`, unindexed computed `CAST(id AS TEXT) AS naive_sort_key`, raw `name ILIKE '%term%'` (not lower(name)), and signed OFFSET. It performs a category and all-images lookup per returned product, then serializes the same primary image contract. Those relationship lookups CAN use PK/image indexes; it would be dishonest to call every operation a sequential scan.

The computed text sort preserves the optimized UUID order exactly; using a mutable or arbitrarily backfilled created_at instead would violate the same-items contract. The baseline does not mutate schema to sabotage the optimized endpoint. Lazy loading is relaxed only within try/finally and restored for the request/Octane worker.

The baseline's intended statement shape is 1 + 2n product/category/image queries. Optimized tests assert <=4 product-list queries and equal counts at page sizes 5 and 50. These are SOURCE TEST ASSERTIONS, not observed query-count evidence in this environment.

### Cursor versus OFFSET

OFFSET must visit/discard preceding rows, and concurrent inserts can shift offsets. A keyset cursor supplies a seek boundary and deterministic order. Expected complexity and planner behavior still depend on filters, selectivity and indexes; no fixed speedup or O(1) latency is claimed. HMAC protects cursor integrity, not confidentiality; the base64url payload is opaque to API consumers, not encrypted. It binds sort, filters, per_page and strategy, and rotates with APP_KEY.

## Bulk data design

The command uses INSERT...SELECT...generate_series with Postgres 18 uuidv7(). No production factories. Products use up to 50,000 rows per statement; image product ranges are reduced so each image statement is at most 50,000 rows. Each statement has a short transaction; there is no million-row all-or-nothing transaction or session temp-table dependency. Progress is printed. Failures retain committed batches; retry explicitly with --truncate and recover a dirty cache after writes are paused.

Design constants, not measured workload proportions:

- roots = min(20, max(1, categories/10 rounded down)); remaining categories are leaves with round-robin root parents.
- hot pool = max(1, floor(leaves/5)); i%10<8 selects from that pool, remaining rows select from all leaves (including hot ones). Thus 80% choose the hot pool by design, not an observed production share.
- i%100<85 active, <95 draft, otherwise archived: 85/10/5 per complete 100-row cycle. Partial tails differ by construction.
- price tiers: 70% use 50,000..999,999; next 25% 1,000,000..9,999,999; final 5% 10,000,000..99,999,999 minor units, deterministic modular arithmetic capped by configured safe max. These are fixture ranges, not inferred market prices.
- 32-word list includes Persian (e.g. گلدان، زعفران، ترمه) and English. SKU/slug are unique index-derived strings.
- generated UUIDs are fresh time-ordered IDs, not byte-for-byte repeatable IDs. Attribute distributions and explicit created_at fixtures are index-derived and repeatable. Record concrete category/cursor UUIDs for each run.
- exactly **three** tables exist: categories, products, product_images. The task's “four tables” reference is inconsistent with its schema; no fourth table is invented. ANALYZE covers all three.

## Cache behavior and trade-offs

See ADR 0004: named cache payload store, named state versions/locks/dirty counter, flexible + a whole cold-compute lock, global invalidation, bounded lease, epoch recovery and fail-open public reads. q bypasses server caching to avoid LRU churn. A global write bump makes all pages cold, creating a DB load burst; category-scoped versions are a future measured optimization, not implemented here.

HTTP public max-age=30 plus stale-while-revalidate=60 permits up to 90 seconds of client/CDN residency. Server flexible [60,180] has its own freshness age; adding HTTP cache residency to a stale server representation can make total data age greater than 90 seconds. Admin no-store cannot purge previously cached public responses. State failure during admin writes fails closed or explicitly reports committed/invalidation-pending; reads still use the DB.

## Integration suite and flakiness policy

`make test-int` uses aghigh_test, direct migrator Postgres and isolated Redis DBs. Stampede workers are separate PHP processes with a ready/go barrier, invoking the real HTTP kernel. An actual products SELECT increments a state-store counter; the test requires exactly one cold computation. It does not prove behavior past the configured lock lease.

The plan test seeds 60,000 products, ANALYZEs and inspects EXPLAIN FORMAT JSON for category, both price orders, category+price and selective Persian search. Naive shapes must show Seq Scan. No enable_seqscan=off, fabricated output, retries to hide failure or automatic skips are used. Planner expectations are **unexecuted and potentially environment-sensitive**, not known flaky. If repeated target runs prove a flaky expectation, preserve failing raw plans, label it and justify any quarantine with a linked issue; do not silently remove it.
