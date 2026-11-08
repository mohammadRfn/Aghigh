# ADR 0009: Chat message sequencing and support-queue claims

Status: accepted (Task 8)

## Context

Every message needs a per-conversation, gap-free, strictly increasing `seq` so clients can order, dedupe and detect
lost broadcasts. Support agents race to claim waiting conversations; each conversation must go to exactly one agent.
Postgres sits behind PgBouncer in transaction mode (no session state); Redis `state` is noeviction but not the truth.

## Decision 1: sequence = counter column on the conversation row

`UPDATE conversations SET last_seq = last_seq + 1 WHERE id = ? RETURNING last_seq` and the message INSERT run in one
short transaction. The row lock serializes senders of one conversation only; a rollback also rolls the counter back, so
there are no gaps. Idempotency: look up `(conversation_id, client_message_id)` first; the unique violation of a racing
duplicate aborts the Postgres transaction, so it is caught outside `DB::transaction` and the existing row is returned.

| Option | Gap-free | Per conversation | PgBouncer-safe | Notes |
|---|---|---|---|---|
| **Counter row (chosen)** | yes (rolls back) | yes | yes | one hot row per conversation; chat conversations are low-rate (a human types), contention is per conversation |
| Postgres `SEQUENCE` | no (nextval is not transactional) | no (one sequence per conversation is unmanageable) | yes | gaps on every rollback; clients cannot distinguish a gap from a lost message |
| Advisory lock + `MAX(seq)+1` | yes | yes | only `pg_advisory_xact_lock` | an extra lock plus an index scan per insert; the counter row lock does the same job |
| Timestamps / UUIDv7 order | no | n/a | yes | not dense: gap detection impossible; clock skew between app nodes |

## Decision 2: Redis queue with a database guard for claims

`ZPOPMIN chat:support-queue` hands each entry to exactly one claimer (atomic in Redis), then
`UPDATE conversations SET status='claimed', assigned_agent_id=? WHERE id=? AND status='waiting'` is the real guard,
plus the assignment insert in the same transaction. 0 rows -> stale entry, dropped (not re-added). DB error -> re-added
with the original score. A crash between both steps leaves a waiting conversation missing from Redis: the scheduled
sweep (and `chat:rebuild-queue`) re-adds it with `ZADD NX` (score = created_at) and removes non-waiting entries.

| Option | Contention | Fairness | Needs | Notes |
|---|---|---|---|---|
| **Redis ZPOPMIN + conditional UPDATE (chosen)** | none in Postgres: each agent touches a different row | FIFO by enqueue time | sweep for repair | O(log N) pop; DB stays the truth; presence/queue live on `state` like other ephemeral state |
| `SELECT ... FOR UPDATE SKIP LOCKED LIMIT 1` | low, but every claim scans the waiting index and takes row locks | FIFO | nothing else | simplest and fully transactional; a valid alternative. Rejected because Task 10 drives many concurrent claimers through PgBouncer and we want the queue hot path off Postgres; it remains the fallback if Redis is unavailable |
| Advisory locks per conversation | high | none | lock bookkeeping | no benefit over SKIP LOCKED |

## Consequences

- `chat:verify-invariants` checks seq contiguity, claimed => one open assignment, one open conversation per customer,
  and queue membership (after `--rebuild`).
- The Redis queue may briefly hold stale entries (harmless: the claim UPDATE drops them) or miss entries (repaired
  within one sweep interval, 1 minute).
