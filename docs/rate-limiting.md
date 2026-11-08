# Rate limiting (Task 7)

All counters live on the `state` Redis (`redis-queue` db 2, `noeviction`, ADR 0002). Numbers in this
document are **configuration, not measurements**; anything that needs a benchmark is marked `TODO(measure)`.

## 1. What limits what

| Layer | Where | Counts | Key | Purpose |
|---|---|---|---|---|
| HTTP limiter (`throttle.sliding:{name}`) | route middleware | every request on the route, allowed or not | per IP / per user / both (config `by`) | protect capacity, slow brute force |
| Concurrency cap (`concurrency.cap:orders`) | route middleware, after auth | requests **in flight** right now | per user | one user cannot hold many PgBouncer/Octane workers |
| Business limit (`OrderRateBudget`) | inside `PlaceOrder`, after the idempotency replay check | order **attempts** that reach the transaction | per user | "orders per minute" as a product rule |
| Account lockout (`identity.lockout.*`, Task 3) | `Accounts` / DB | failed passwords per **account** | per user row | stop password guessing across IPs |

HTTP limiter vs business limit: the HTTP `orders` limiter counts `GET /orders` as much as `POST /orders`
and counts idempotent replays; it is about request volume. The business limit counts only genuine new
attempts: a replay of an `Idempotency-Key` is answered from the cache, or from the completed `idempotency_keys` row on a cache miss, before the budget is touched, so
retries of a timed-out call are free. The budget is consumed in Redis **outside** the DB transaction, so an
attempt that rolls back (409 `out-of-stock`, 409 `idempotency-in-progress`) **does** consume budget: it was an
attempt and it did cost a transaction. Both decisions are tested in `OrderRateLimitTest`.

Lockout vs `auth-login`: the limiter is keyed by `IP + HMAC(email)` and triggers before the password is
checked; lockout is keyed by account and triggers on wrong passwords regardless of IP. They are independent
mechanisms and both stay. A locked account still consumes limiter budget.

`/up` carries no limiter middleware (health checks are exempt).

## 2. Named limiters (config/rate-limits.php)

| Name | Routes | Key | Limit / 60 s | Driver | Fail mode | Why |
|---|---|---|---|---|---|---|
| `auth-login` | `POST /auth/login` | ip+email tag | 10 | laravel | closed | Task 3 value; small enough to blunt credential stuffing per IP/email pair, large enough for a typo-prone human |
| `auth-register` | `POST /auth/register`, `GET /auth/email/verify/{id}/{hash}` | ip | 5 | laravel | closed | registration is expensive (argon2id + mail) |
| `auth-forgot-password` | `POST /auth/forgot-password`, `POST /auth/reset-password` | ip | 5 | laravel | closed | mail flood / enumeration |
| `auth-email-resend` | `POST /auth/email/resend` | ip | 5 | laravel | closed | mail flood |
| `auth-refresh` | `POST /auth/refresh` | ip | 30 | laravel | closed | refresh rotation is one SQL write; legit clients refresh rarely |
| `orders-ip` | all `/orders*` (pre-auth) | ip | 120 | laravel | closed | shield auth:api from unauthenticated floods |
| `orders` | all `/orders*` (post-auth) | user | 30 | **sliding** | closed | the only sliding-log limiter; per-user accuracy matters here |
| `chat-messages` | `POST /conversations/{id}/messages` (after `auth:api`) | user | 60 | laravel | closed | one message per second per user; writes must not run unthrottled |
| `chat-typing` | `POST /conversations/{id}/typing` | user | 60 | laravel | **open** | ephemeral; the broadcast itself is throttled to 1 per 3 s (SET NX EX) |
| `chat-heartbeat` | `POST /presence/heartbeat`, `GET /presence` | user | 12 | laravel | **open** | one heartbeat per ~5 s per user is plenty for a 60 s presence TTL |
| `chat-read` | `POST /conversations/{id}/read` | user | 120 | laravel | **open** | idempotent marker update |
| `chat-claim` | `POST /support/queue/claim` | user | 60 | laravel | closed | protects queue integrity and the claim transaction |
| `chat-connections` (concurrency section) | channel auth (`POST /api/broadcasting/auth`) | user | 5 distinct sockets per 120 s | Lua in Chat (`chat:conn:{user}`) | closed | approximate per-user WebSocket cap (docs/chat-protocol.md) |
| `public-catalog` | `GET /products*`, `GET /categories` | ip | 300 | laravel | **open** | cached reads; availability beats precision |
| `admin` | `/admin/*`, `PATCH /users/{id}/role`, inventory admin | user | 120 | laravel | closed | small audience, keep abuse of a stolen admin token bounded |
| `webhook` | `POST /payments/webhook` | ip | 600 | laravel | **open** | the HMAC signature is the gate; the limiter only stops floods |
| `broadcasting-auth` | `POST /api/broadcasting/auth` | user+ip | 60 | laravel | closed | one auth per channel subscribe; reconnect storms are bounded per device |

Concurrency cap: `orders` max **2** in flight per user, slot TTL **30 s** (> any order request: PgBouncer
`lock_timeout`s and the gateway timeout are all far below). Business limit: **10** order attempts per user
per minute.

Pre-auth limiters run before `auth:api`, so a flood from one IP is rejected without hitting the JWT guard
or the database. Order of middleware on `POST /orders`:
`throttle.sliding:orders-ip -> auth:api -> throttle.sliding:orders -> concurrency.cap:orders -> RequireIdempotencyKey`.
A 429 is thrown as an exception, so nothing after it runs: no idempotency row, no cache entry.

## 3. Algorithms compared

| | Fixed window (Laravel `RateLimiter`) | Sliding log (ours, Lua) | Token bucket |
|---|---|---|---|
| Memory per key | 1 counter | up to `limit` members in a sorted set | 2 numbers |
| Accuracy | allows up to 2x the limit across a boundary | exact: at most `limit` in **any** window | exact average, allows a burst of bucket size |
| Atomic in Redis | yes (INCR) | yes (one script) | needs a script |
| Retry-After precision | window end | exact (oldest member + window) | computable |
| Cost per call | O(1) | O(log N + evicted) | O(1) |

We use the fixed window for everything where a 2x boundary burst is harmless (it is cheaper and already
battle-tested) and the sliding log only for `orders`, where "30 per minute" should mean what it says for
every single user and where Retry-After must be precise. Token bucket was not chosen because it needs the
same Lua machinery as the log without giving exact window semantics, and its burst behaviour is exactly
what we want to avoid for order placement.

### Memory cost of the sorted set

Each allowed attempt adds one member (UUIDv7 string, ~36 bytes + ziplist/listpack overhead, roughly 60-80
bytes). Worst case per key is `limit` members, so the bound is `limit x active keys x ~80 B`. With
`orders` at 30 and 10 000 active users that is about 24 MB, and the `PEXPIRE window` on every call makes a
key disappear one window after its owner goes quiet, so "active" means "touched in the last 60 s". The
`state` Redis is `noeviction`: if it filled up, writes (including the JWT denylist and the idempotency cache)
would start failing, which is why `rate-limits.sliding_max_limit` (default 1000) hard-caps any sliding
limit, multiplier included. Denied attempts add **no** member, so an attacker cannot grow a key beyond
`limit`. `TODO(measure)`: actual bytes per key under the current Redis version.

### Why Lua and Redis `TIME`

The decision (evict, count, add, expire) must be atomic or two workers can both see `limit - 1` and both
add. A `MULTI` cannot branch; a script can. The script reads `TIME` inside Redis, so 20 Octane workers on
machines with drifting clocks agree on one clock, and the test-only explicit `now` argument lets the
contract test drive the window deterministically. Redis 7 replicates scripts by effects, so `TIME` before a
write is allowed. Scripts are called with `EVALSHA` and fall back to `EVAL` on `NOSCRIPT` (fresh instance,
`SCRIPT FLUSH`, failover); there is no "script loaded" flag anywhere, which keeps the class Octane-safe.

## 4. Responses

* 429 is problem+json (`ProblemDetails`), `type .../problems/too-many-requests`, extension `retry_after`.
  Other 429 types: `concurrency-limit-exceeded`, `orders-per-minute-exceeded`.
* Headers on 429 **and** on success: `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`
  (delta seconds, IETF draft). `Retry-After` (whole seconds, ceil of the millisecond value) on 429.
  Legacy `X-RateLimit-*` are gone. With stacked limiters the most restrictive result wins (fewest remaining,
  then the later reset). All four are in `Access-Control-Expose-Headers` (config/cors.php).
* Fail-closed backends answer **503** `rate-limit-unavailable` with `Retry-After: 2` (not 429: the client
  did nothing wrong, and 503 is what load balancers treat as "try another time"). Fail-open backends serve
  the request without `RateLimit-*` headers. Both dispatch `RateLimitBackendFailed`.

## 5. Failure modes

| Situation | catalog / webhook (open) | auth / orders / admin (closed) |
|---|---|---|
| state Redis down | served, no headers, event + `security` error log | 503 `rate-limit-unavailable` |
| NOSCRIPT | transparent EVAL fallback | same |
| Redis full (`noeviction`) | served | 503 (write fails) |
| worker crash holding a semaphore slot | n/a | slot expires after `ttl_ms` (30 s) |
| counter store down for log sampling | log every hit (sampling is best effort) | same |

`RATE_LIMIT_MULTIPLIER` scales every limit (`ceil(limit x factor)`, min 1, factor clamped to
`[0.01, 10000]`) but only when `APP_ENV` is `local`, `testing` or `loadtest` (`UnsafeEnvironment`).
Elsewhere the registry ignores it **and** the provider boot throws, so a leaked `.env` line fails fast
instead of silently weakening production. Limits can be scaled, never turned off.

## 6. Layering with Traefik

The application is the limiter. Traefik (Task 11) will terminate TLS, strip client-supplied
`X-Forwarded-*` and add its own; it must **not** add a second rate limit that duplicates these (its
in-memory limiter is per Traefik replica and cannot see users). Traefik's only job here is to be the one
trusted proxy. `TRUSTED_PROXIES` defaults to the compose `edge` network (`172.30.0.0/24`, see
`compose.yaml`), where only Traefik lives; `Request::ip()` therefore returns the real client for traffic
that came through Traefik and the peer address for anything else. Nothing in the code reads
`X-Forwarded-For` by hand (`tests/Arch/RateLimitingBoundaryTest.php`). `scripts/verify-trusted-proxy.sh`
proves this against the real stack with curl, including a forged header from inside the backend network.

## 7. Observability

Every rejection: `security` channel, message `rate_limit_hit`, fields `limiter`, `key_hash` (16 hex chars of
`HMAC-SHA256(app.key, key)`, never a raw IP/user/email pair, never a plain sha256 of an email), `ip`,
`route`, `driver`, `retry_after`, `suppressed`. After `RATE_LIMIT_LOG_SAMPLE_AFTER` (20) hits for one key in
one window, only every `RATE_LIMIT_LOG_SAMPLE_EVERY` (100)th hit is logged, with `suppressed` = lines skipped,
so an attack cannot fill the disk. The events `Modules\Shared\Events\RateLimitHit` and
`RateLimitBackendFailed` are dispatched for **every** hit (not sampled) for Task 9 metrics; Task 10 can count
either the fixed message or the event.

## 8. Load tests

Set `APP_ENV=loadtest` and `RATE_LIMIT_MULTIPLIER=20` (or any factor) in the load-test environment: all
limits scale proportionally, sliding ones capped at `RATE_LIMIT_SLIDING_MAX_LIMIT`. Never set the
multiplier in `.env.docker` of a production-like run; the boot check will refuse it anyway. To test the
limiter itself under load, leave the multiplier at 1 and expect 429s: they are the feature.

## 9. Tests

* `make test`: in-memory `SlidingWindowLimiter` (RATE_LIMIT_SLIDING_DRIVER=memory in phpunit.xml),
  multiplier 1000 so unrelated tests never trip; limiter tests call `RateLimitTesting::exact()`.
  Shape/headers, per-IP vs per-user isolation, trusted vs untrusted proxy, fail-open/closed, no idempotency
  key on 429, replays free / 409 counted, logs + events + sampling, multiplier guard, webhook, broadcasting.
* `make test-int`: the Lua limiter and semaphore on real Redis: shared contract test, burst, steady rate,
  window slide, reset, many keys, 50 processes vs limit 10 = exactly 10, semaphore never above cap, release
  after exception and after expiry, NOSCRIPT after `SCRIPT FLUSH`.
* Arch: only `Modules\Shared\RateLimiting` touches limiter keys/scripts; feature modules never use
  `Illuminate\Cache\RateLimiter` directly.
