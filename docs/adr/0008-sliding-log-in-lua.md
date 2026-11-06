# ADR 0008: Sliding log in Lua for per-user order limits, Laravel's limiter everywhere else

Status: accepted (Task 7). Supersedes nothing; extends ADR 0002 (Redis roles).

## Context

Task 3 introduced three named Laravel limiters (login, register, forgot-password) on the `state` Redis.
Task 7 needs limits on every public surface, per-user limits after JWT auth, a concurrency cap on order
placement and a business "orders per minute" rule, all under Octane (long-lived workers, no per-request
state in singletons) and behind PgBouncer (no session state to lean on).

Laravel's `RateLimiter` is a fixed window on `INCR` + `EXPIRE`: cheap, proven, but it lets up to 2x the
limit through around a window boundary and its `Retry-After` is "until the window ends". For order
placement, which is the one endpoint that costs a reservation, a saga and a payment, we want "30 per
minute per user" to be exactly that, with a precise `Retry-After`.

## Decision

1. **One middleware, two engines.** `throttle.sliding:{name}` reads the limiter from
   `config/rate-limits.php`; the limiter's `driver` is `laravel` (wraps `Illuminate\Cache\RateLimiter`) or
   `sliding` (our `SlidingWindowLimiter` contract). Headers, logs, events and the 429 body are identical.
2. **Sliding log in Lua** for `sliding`: one sorted set per key, score = Redis `TIME` in ms, member =
   UUIDv7. `ZREMRANGEBYSCORE` old entries, `ZCARD`, conditional `ZADD`, `PEXPIRE window`, computed
   `retry_after` and `reset` from the oldest member, all in one `EVALSHA` (fallback `EVAL` on `NOSCRIPT`).
   Denied attempts add no member. Memory per key is bounded by `limit`, and `sliding_max_limit` caps
   `limit` (multiplier included) because `state` is `noeviction`.
3. **Only `orders` uses `sliding`.** Everything else keeps the fixed window: it is cheaper, boundary
   bursts are harmless there, and the smaller the Lua surface the smaller the operational surface.
4. **Same contract, two implementations.** `RedisSlidingWindowLimiter` (production, Concurrency suite)
   and `InMemorySlidingWindowLimiter` (Feature suite, refused outside `UnsafeEnvironment`). One contract
   test runs against both; the explicit `now` argument exists for that test only.
5. **Concurrency cap** is a second small script (sorted set of in-flight ids scored by expiry), released
   in `finally`, owner-only, expiry > longest request.
6. **Fail mode per limiter**: open for cached reads and the HMAC-guarded webhook, closed (503
   `rate-limit-unavailable`) for auth, orders and admin.
7. **Client IP only through `TrustProxies`**, default = the compose edge network; verified against the
   real Traefik with curl.

## Alternatives considered

* **Laravel limiter only.** Simplest; rejected for `orders` because of the boundary burst and the coarse
  `Retry-After`. Kept for every other limiter.
* **Token bucket in Lua.** Same atomicity needs, O(1) memory, but burst-by-design; the one place we
  wanted precision is the one place a burst is unwanted. Not chosen.
* **Sliding window counter (two fixed windows weighted).** Approximate; cheaper than the log but still
  approximate, and we already pay for one script. Not chosen.
* **Traefik rate limiting.** Per-replica, per-IP only, cannot see users or idempotency; useful as a coarse
  outer shield in Task 11 but not as the product limiter.
* **`ThrottleRequestsWithRedis`.** Laravel's own Lua-free Redis limiter (`DurationLimiter`) is still a
  fixed window. Not chosen for the same reason as the array-backed one.

## Consequences

* Exact per-user limits and exact `Retry-After` on orders; cheap fixed windows elsewhere.
* Memory on `state` is bounded and documented; operators must keep `sliding_max_limit` honest.
* Redis >= 7 is assumed (effects replication for `TIME` inside scripts). On older Redis the script would
  need `redis.replicate_commands()`.
* Two drivers means two code paths for the Concurrency suite to cover; the shared contract test keeps
  them from drifting.
* The business limit (`OrderRateBudget`) reuses the same contract, so it inherits the same guarantees
  and the same tests.
