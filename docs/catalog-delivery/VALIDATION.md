# Catalog delivery: validation and limitations

## What was actually checked here

- Upstream public repository fetched at b634d7706fe2e3eb3ae23d347444d6a26218f269; local delivery branch feat/catalog created. No upstream push or pull request was made.
- AGENTS.md, ADR 0001–0003, CurrentUser, ProblemException, providers, cache, test helpers, Makefile and CI inspected before implementation.
- PHP source parsed with the JavaScript php-parser grammar (PHP 8.3-compatible syntax). This is a syntax smoke check only, NOT PHP execution, PHP 8.5 validation, Pint or Larastan.
- ZIP source paths and patch applicability checked against the pinned base during packaging. See manifest/checksum.
- A separate Next.js/PostgreSQL delivery UI exposes package downloads, file browsing, a persisted installation checklist and a read-only contract preview. Its build/tests do not validate the Laravel artifact.

## What has NOT been run

The delivery environment has no PHP 8.5 runtime and no Docker executable/daemon. Following AGENTS.md, no host PHP/Artisan/Composer workaround was used.

| Required release check | Status |
|---|---|
| make lint / Pint | NOT RUN — target container required |
| make stan / Larastan | NOT RUN — target container required |
| make test / SQLite Unit, Feature, Arch | NOT RUN — target container required |
| make test-int / PostgreSQL 18 + Redis | NOT RUN — target container required |
| fresh-seed + 100,000 products + both endpoints | NOT RUN — target container required |
| 1,000,000-product scale | NOT RUN — TODO(measure) |
| Actual EXPLAIN before/after | NOT RUN — TODO(measure) |
| PHP 8.5 Docker image compatibility | NOT RUN |
| Generated Scramble /docs/api rendering | NOT RUN |
| GitHub CI | NOT RUN — no authenticated remote write |

**Task 4's full definition of done has not been proven. Do not label this package CI-green or production-ready.** Source tests and commands are included so the remaining work is reproducible.

## Highest-priority target checks

1. Composer autoload discovers new Catalog classes; provider merges `catalog` config.
2. Pint and Larastan level 5. Run Pint in the container to apply style if needed, then lint again; no baseline suppressions were added for Catalog.
3. PostgreSQL UUID ofMany cast path; equal-price cursor boundaries; cold stampede counter exactly once; both cache/state failure paths; deferred cache refresh guard on Laravel 13.
4. Actual pg_trgm partial planner choice with selective Persian q and realistic buffers. The candidate partial index is not claimed proven. The composite category-price write cost is not measured.
5. Anonymous 401/customer 403/admin success matrix against working Task 3 keys and roles; target Task 3 status itself must be confirmed.
6. Post-commit state failure returns explicit ambiguous-write 503 and preserves dirty bypass; recovery requires paused admin/bulk writes.
7. Ensure `ordering.max_items` in Task 5 is <= `catalog.pricing_max_ids` and keep the integration guard synchronized.
8. Keep lock lease above measured worst-case cold compute; default 15 seconds is not a measured safety guarantee.

## Coverage source map

- Unit/BenchEnvironmentTest: explicit environment allowlist.
- Feature/CatalogReadTest: bounded query count, same items, page limits, signed cursors, inactive hiding, price ties, headers and categories.
- Feature/CatalogAdminTest: authorization, admin headers, both invalidations, money/format/image validation, cycles, subtree depth, archive transitions.
- Feature/CatalogCacheTest: flexible stale refresh, q bypass, both dependency failures, lock timeout without storage, dirty/version reset behavior.
- Feature/ProductPricingTest: exact database prices, inactive/unknown IDs, caps and cache independence.
- Feature/CatalogRegressionTest: real DB fallback under both cache failures, audit field allowlist and deterministic image position/UUID tie-break.
- Feature/BenchRoutesTest: route registry absence in staging/production.
- Arch/CatalogBoundaryTest and extended ModulesTest: allowed public surface only.
- Concurrency/StampedeTest: independent barrier-synchronized HTTP workers and state counter.
- Concurrency/BulkSeedTest: seed row counts, uniqueness, UUIDv7, truncate and production refusal.
- Concurrency/QueryPlanTest: >=50k rows, real planner expectations and PostgreSQL Persian/ofMany behavior.

No passing count, coverage percentage, latency, throughput or fabricated output is supplied.
