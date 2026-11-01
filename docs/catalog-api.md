# Catalog API contract — Task 4

All paths have prefix `/api/v1`. Scramble discovers the module routes at `/docs/api`; Catalog, Catalog admin and `_bench` have distinct Group attributes. Controller documentation and FormRequest rules are included. Inspect generated OpenAPI in the actual container before release; it has not been rendered here.

## Endpoint inventory

| Method | Path (after /api/v1) | Authorization | Behavior |
|---|---|---|---|
| GET | /products | Public | Active products; indexed/keyset/cached |
| GET | /products/{identifier} | Public | UUID or slug, active only, all image metadata |
| GET | /categories | Public | Position-ordered category tree |
| POST | /admin/products | auth:api + CurrentUser admin | Create; 201 |
| PATCH | /admin/products/{id} | auth:api + CurrentUser admin | Partial update; images replace as one set |
| GET | /admin/products/{id} | auth:api + CurrentUser admin | Any status |
| POST | /admin/categories | auth:api + CurrentUser admin | Create; 201 |
| PATCH | /admin/categories/{id} | auth:api + CurrentUser admin | Cycle/depth-safe parent change |
| GET | /_bench/naive/products | Local/testing/loadtest only | No cache; deliberate N+1 + signed OFFSET |

No DELETE, upload or rate-limiter endpoints. Archive a product through PATCH status. Archived → active is rejected: archived → draft → active is required. Draft ↔ active and either → archived are allowed. Public product detail never reveals draft/archived rows, even to an authenticated admin; use the admin route.

## Product list query

| Parameter | Rule / default |
|---|---|
| category | Optional category UUID; exact membership (not subtree) |
| q | Optional 3–100 Unicode characters; trimmed, lowercased; literal substring; %, _ and backslash escaped |
| min_price, max_price | Optional non-negative bounded integer minor units; min <= max |
| sort | newest (default), price_asc, price_desc |
| per_page | Configured default 20; configured max <= 50; above max gives 422, never clamped |
| cursor | Opaque base64url payload + HMAC; bound to sort, normalized filters, page size and implementation |

newest orders UUIDv7 IDs descending. Price ordering uses (price_minor, id) both in the same direction. Cursors intentionally cannot be reused after changing filters, page size or strategy; bad/malformed/tampered tokens give 400 problem+json. There is no total-count query. A next_cursor of null means the end of the result set. Cache normalization includes explicit defaults and sorted keys.

The naive endpoint uses the same fields and items but its cursor encodes an OFFSET and is implementation-bound. An unindexed computed text UUID sort column preserves the public order while preventing a UUID B-tree ordering shortcut. Prices use an unindexed +0 expression. The baseline still shares PK, unique, FK and optimized indexes; it defeats the useful query shapes rather than dropping integrity constraints.

## Response fields

List: `{data: [product...], meta: {per_page: integer, next_cursor: string|null}}`.

Product fields: id, sku, name, slug, description, price_minor, currency, category (id/name/slug), primary_image (nullable). Public detail additionally includes images. Image fields: id, url, path, width, height, position, alt. URL is rtrim(catalog.image_base_url) + '/' + relative path. Admin responses also contain status and category_id. No internal actor/user models leave the module.

Category tree: `{data: [{id, name, slug, position, children: [...]}]}`. Admin category responses contain id, parent_id, name, slug, position. Category root counts as depth 1. The complete moved subtree must fit the configured maximum.

## Admin request bodies

Product creation requires category_id (existing UUID), sku (uppercase ASCII letters/digits with ._-), name, slug (lowercase kebab-case), positive bounded integer price_minor and catalog currency. description is optional up to 20,000 characters. status defaults to draft. PATCH validates only supplied fields, never accepts arbitrary columns. Unique SKU and slug constraints are enforced in validation and SQL.

images, when supplied, is an array of at most CATALOG_IMAGES_MAX objects. Each requires a relative path (<=500 characters, no traversal, schemes or query strings), width/height integers 1..20000, distinct position 0..1000000, and alt 1..250 characters. Omit images to preserve; [] clears. Replacement and product write share a short transaction with a product-row lock on updates.

Category creation requires name and unique kebab-case slug; position defaults to 0; parent_id is nullable and must exist inside Catalog. Name max 160, slug max 160. A PostgreSQL transaction advisory lock serializes topology checks; SQLite suites test the same cycle/depth algorithm without the PostgreSQL statement.

## Headers and errors

Public GETs: Cache-Control: public, max-age=30, stale-while-revalidate=60. Weak ETag supports If-None-Match and 304. No cookies. Search bypasses server cache but uses the same HTTP headers. Client/CDN residency may remain stale up to 90 seconds; see ADR 0004 for separate server stale age.

Admin: Cache-Control: private, no-store. Naive: Cache-Control: no-store. X-Request-Id remains supplied by Shared.

400 bad cursor; 401 missing/invalid Identity bearer token; 403 non-admin; 404 hidden/missing product; 422 field/cycle/transition/cap validation; 503 write-state dependency unavailable or committed write with pending invalidation. Domain errors implement Shared Contracts ProblemException and are rendered as RFC 9457 application/problem+json. Public cache/state dependency errors fall back to DB, not 500. Genuine DB failures are not mislabeled as successful reads.

## Internal contract

`Modules\\Catalog\\Contracts\\ProductPricing::forProducts(array $ids)` returns ProductPrice DTOs keyed by UUID with integer price_minor, currency and bool active. Unknown IDs omitted, inactive returned false, input cap checked before deduplication. No cache read/write. Other modules must not import Catalog models, services, HTTP, policies, factories or relations.

## Try locally

`curl -i 'http://localhost:8000/api/v1/products?per_page=5&sort=price_asc'`

`curl -i 'http://localhost:8000/api/v1/_bench/naive/products?per_page=5&sort=price_asc'`

For admin routes, obtain a real admin access token through Task 3. Do not paste access tokens into logs, committed curl files or this delivery workspace. The workspace explorer is an explicitly separate Next.js/PostgreSQL contract preview, not the Laravel runtime.
