# ADR 0004: Catalog cache ownership and single-currency pricing

Status: proposed — source implementation delivered; PHP 8.5 / PostgreSQL 18 / Redis validation pending.

## Context

Read AGENTS.md and ADR 0001–0003. Identity already exposes CurrentUser and roles in upstream commit b634d7706fe2e3eb3ae23d347444d6a26218f269. Its own target validation is not assumed complete. Catalog must not import Identity internals. Evictable Redis data must never hold correctness state. HTTP caches cannot be synchronously invalidated by an admin SQL commit.

## Decision

### One catalog, one currency

`catalog.currency` defaults to IRR. It is a configuration choice, not a measurement or an exchange-rate service. Every product stores that ISO currency code alongside positive `price_minor` bigint money. Model saves and admin requests enforce equality with the configured currency; bulk SQL inserts only that currency. Filters and sorts use those minor units, never floats. The configurable ceiling defaults to 9007199254740991 (JSON safe integer, below signed bigint).

Changing the currency env variable is NOT currency conversion. Pause writes, migrate every price/currency deliberately, invalidate cache, then deploy matching configuration. No mixed-currency sort or automatic conversion is supplied.

`ProductPricing` reads the DB, bypasses every cache dependency, includes inactive flags and omits unknown IDs. It bounds the input before deduplication. Default 100 is a capacity guard, not a measurement. Task 5 must wire its real item cap to `ordering.max_items` or revise the explicit integration check; 100 is the provisional floor before Task 5 exists.

### Redis roles

The named `cache` store is newly defined in `config/cache.php`: connection `cache`, **lock_connection `state`**. Payloads are plain arrays and disposable. Laravel flexible refresh locks therefore go to state, not the evicting instance. `CatalogState` explicitly uses the state store for cold locks, write locks, both version counters, an epoch and a dirty-write count. There are no new SQL cache tables.

Keys are `catalog:list:v{epoch}.{products}.{categories}:{sha256(normalized params)}`. Params are trimmed/lower-cased, defaults filled, numeric values cast, and keys sorted before hashing. Search (`q`) bypasses server cache due to cardinality but keeps identical HTTP caching semantics.

`Cache::store('cache')->flexible` implements stale-while-revalidate. On a cold miss, a state lock is held through the complete flexible computation **and storage**, so waiting requests recheck after acquiring. A lock timeout computes directly from DB without storing. Configurable lease/wait defaults 15/5 seconds are NOT measured safe values; measure worst-case computation duration and size the lease accordingly. A computation longer than the lease can permit duplicate work. The integration test covers normal lease duration, not arbitrary lease overruns.

Fresh/stale defaults are [60, 180] seconds: the second value is the TOTAL lifetime, not 180 additional seconds. A stale value is returned while Laravel refreshes after the response. PageCache wraps its named deferred callback as well as the synchronous operation so cache/lock/storage failures log a warning to `app`, do not escape into a 500, and leave DB reads available. This wrapper depends on the Laravel 13 DeferredCallbackCollection public interface and the flexible callback name; retain its regression test when upgrading Laravel.

### Global invalidation, including interrupted writes

Both `products` and `categories` state counters advance after every successful admin SQL write. This is deliberately global: a category rename changes embedded category data on product pages. It also makes the entire catalog cold after any write, increasing DB work and reducing hit ratio in write-heavy workloads. Per-category versions could preserve unaffected pages but complicate category moves, unfiltered listings, old/new membership and tree dependencies. Global invalidation is the chosen starting point; measure before narrowing it.

The state dirty counter is incremented BEFORE SQL and decremented AFTER post-commit version bumps. While positive, readers bypass cache. A counter, rather than a boolean marker, tolerates overlapping writers if a lease expires. Failed SQL decrements its own reservation. If state fails after SQL commit, the response explicitly reports `catalog-invalidation-pending` (503); the write may already be durable. Inspect before retrying. The positive marker prevents stale payloads from reappearing after Redis recovers. Pause all admin and bulk writes, wait for in-flight SQL, then run `catalog:cache-reconcile --confirm-writes-paused` to bump both counters and clear the marker. Never treat this as a normal cache flush.

A missing version component creates a fresh random epoch under an initialization lock, not a reused v1 namespace. Catastrophic/partial state loss is an operational incident; follow ADR 0003 as well, because Identity correctness uses the same state instance. Restore with writes paused. Redis/SQL are not claimed to be a distributed transaction.

### HTTP

Public GETs send `public, max-age=30, stale-while-revalidate=60`, weak ETags and 304 support, without cookies. Clients/CDNs may display an older representation for up to 90 seconds after they cached it (30 + 60), even after a successful server invalidation. These values are configured protocol policy, not measured response times. An already stale server payload can be older still; distinguish server freshness from client residency.

Admin responses, including rendered failures, send `private, no-store`. The naive endpoint sends `no-store` and never uses page cache. No public/admin rate limiter is added here; Task 7 owns those groups.

## Consequences and pending evidence

- More state operations per read/write; no state keys can be evicted by payload-cache LRU.
- Dirty-write fallback and global invalidation trade availability of cached data for correctness.
- Category moves use a PostgreSQL transaction advisory lock, independent of Redis lease duration. No session advisory locks, cross-module foreign keys or session middleware.
- Image ofMany uses UUID text aggregation and casts back on Postgres, since PostgreSQL has no min(uuid). This is internal to Catalog and has an integration regression test.
- All timing, index size, hit rate and write amplification: TODO(measure).
- PHPUnit/Pest, Pint, Larastan, real concurrency and CI: not executed in the delivery environment. See docs/catalog-delivery/VALIDATION.md.
