# ADR 0002: Two Redis instances, three connections

Status: accepted

| Connection | Instance | Policy | Holds |
|---|---|---|---|
| `cache` | redis-cache | allkeys-lru, no persistence | catalog cache only |
| `default` | redis-queue | noeviction, AOF, maxmemory 300mb | queues, Horizon, Reverb pub/sub |
| `state` | redis-queue (db 2) | noeviction, AOF | JWT denylist, rate limiter, idempotency, presence, unread counters, support queue, locks |

Why: an LRU Redis may drop any key under memory pressure. Losing a revoked-token entry or a lock silently
breaks security or correctness, so those live on a non-evicting instance. `maxmemory` is set so a full
instance returns errors instead of being killed by the container memory limit.
