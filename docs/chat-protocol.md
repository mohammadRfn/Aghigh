# Chat protocol (Task 8)

REST is the source of truth. WebSockets (Reverb, Pusher protocol 7) only **notify** about rows that are already
committed. A lost broadcast never loses data: clients recover with gap-filling. Values below are configuration,
not measurements; anything unmeasured is marked `TODO(measure)`.

## Addresses

| Role | Who | Address |
|---|---|---|
| Publish (server -> Reverb HTTP API) | app, horizon | `REVERB_SCHEME://REVERB_HOST:REVERB_PORT` = `http://reverb:8080` on the internal `backend` network |
| Connect (client -> Reverb WebSocket) | browsers, k6 | `VITE_REVERB_SCHEME://VITE_REVERB_HOST:VITE_REVERB_PORT/app/{VITE_REVERB_APP_KEY}` = `ws://localhost:8000/app/...` through Traefik |
| Channel auth | clients | `POST /api/broadcasting/auth` (Task 7 route: `api, auth:api, throttle.sliding:broadcasting-auth`), Echo `authEndpoint: '/api/broadcasting/auth'` |

Traefik routes only `/app/` to Reverb. `/apps/` (Reverb's signed HTTP API: publish, connections, terminate) is **not**
routed; only the app container uses it, directly over the backend network. `scripts/verify-chat.sh` proves it with curl
(the answer to `/apps/...` comes from Laravel: 404 + `X-Request-Id`). `servers.reverb.hostname` only selects a TLS
certificate and reads `REVERB_SERVER_HOSTNAME`, never `REVERB_HOST`.

Echo example:

```js
new Echo({ broadcaster: 'reverb', key: import.meta.env.VITE_REVERB_APP_KEY, wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: import.meta.env.VITE_REVERB_PORT, forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'], authEndpoint: '/api/broadcasting/auth',
  auth: { headers: { Authorization: `Bearer ${accessToken}` } } });
```

## REST endpoints (`/api/v1`, all `NoStore` + `auth:api`)

| Method | Path | Who | Notes |
|---|---|---|---|
| POST | `/conversations` | customer | 201 new, **200** when an open one exists (race-safe: partial unique index) |
| GET | `/conversations` | customer: own; agent: assigned; admin: none | keyset `cursor` (base64url UUIDv7), `per_page`, `status`; each item has `unread` |
| POST | `/conversations/{id}/messages` | customer (waiting/claimed), assigned agent (claimed) | `{body, client_message_id}`; 201, replay 200 + `Idempotency-Replayed: true`; `throttle.sliding:chat-messages` |
| GET | `/conversations/{id}/messages` | participants | newest first, `before_seq` for scrollback; `?after_seq=N&limit=` ascending gap-filling |
| POST | `/conversations/{id}/read` | participants | `{last_read_seq?}` (default = last_seq; never backwards, capped at last_seq) |
| POST | `/conversations/{id}/typing` | participants who may post | 202 `{broadcast: bool}`; throttled broadcast, never persisted |
| POST | `/conversations/{id}/close` | customer, or assigned agent of a claimed conversation | |
| POST | `/conversations/{id}/release` | assigned agent | back to the queue with its original score; assignment history `released` |
| POST | `/support/queue/claim` | support_agent | 200 with the conversation, **204** when the queue is empty |
| POST | `/presence/heartbeat` | anyone | `{socket_id?}` refreshes presence and the socket's connection-cap slot; fails open |
| GET | `/presence` | anyone | customers: `agents_online` only; agents/admins also `online_user_ids` |

Gap-filling: `limit` defaults to 50; the hard maximum is 200 and a larger value is **rejected with 422** (never clamped).
The response has `meta.has_more` and `meta.last_seq` (the conversation counter read before the page: a lower bound).

Message wire shape (REST `data` and the `message.sent` payload):

```json
{ "id": "0197...", "conversation_id": "0197...", "seq": 42, "sender_id": "0197...", "sender_role": "customer",
  "body": "raw text", "client_message_id": "cm_8f2c...", "created_at": "2026-06-01T10:00:00.123Z",
  "server_time": "2026-06-01T10:00:00.140Z" }
```

`server_time` exists only on broadcasts (time the worker built the payload). `body` is stored and returned **raw**
(trimmed, not HTML-escaped): clients must escape it when rendering. The body limit counts **characters** (`mb_strlen`),
default 2000 (`CHAT_BODY_MAX_CHARS`). `client_message_id` has the Idempotency-Key format: 16 to 64 of `[A-Za-z0-9_-]`.

### Problem types (RFC 9457)

| Status | type | When |
|---|---|---|
| 404 | `not-found` | unknown, malformed or foreign conversation (same answer: no existence leak) |
| 403 | `forbidden` | wrong role for the action (e.g. agent opening a conversation, customer claiming) |
| 409 | `conversation-closed` | posting, typing, closing or releasing a closed conversation |
| 409 | `client-message-id-reuse` | same `client_message_id` with a different body or sender |
| 409 | `conversation-not-claimed` | release of a conversation that is not claimed by the caller |
| 409 | `agent-capacity` | `CHAT_AGENT_MAX_OPEN` reached |
| 422 | validation | body empty / too long, bad `client_message_id`, `limit` > 200, `after_seq` with `before_seq` |
| 429 | `too-many-requests` | chat limiters (`Retry-After`, `RateLimit-*`) |
| 503 | `rate-limit-unavailable` / `chat-queue-unavailable` | fail-closed limiter or queue backend down |

## Channels and events

| Channel (Laravel) | Wire name | Who may subscribe |
|---|---|---|
| `conversation.{uuid}` | `private-conversation.{uuid}` | the customer owner, the agent currently assigned |
| `agents.queue` | `private-agents.queue` | support agents |

Admins are denied by default (support conversations are private; an audited admin view is an open decision).
Malformed, unknown and forbidden ids all get the same 403; denials log `chat_channel_denied` on `security` with
hashed ids. Channels are declared in `Modules/Chat/routes/channels.php` with `guards => ['api']`.

| Event (`broadcastAs`) | Channel | Payload |
|---|---|---|
| `message.sent` | conversation | message shape above |
| `conversation.updated` | conversation | conversation shape (status, assigned_agent_id, last_seq, ...) |
| `typing` | conversation | `{conversation_id, user_id, role, expires_in, server_time}` |
| `queue.updated` | agents.queue | `{conversation_id, action: queued|released|claimed|closed, server_time}` |

Echo listens with a leading dot: `.listen('.message.sent', ...)`. Clients never send client events
(`accept_client_events_from = none`).

Broadcast jobs run on the `broadcasts` queue (supervisor timeout 30 s). `message.sent`, `conversation.updated`,
`queue.updated`: tries 3, backoff 1/3/10 s, timeout 20 s, `ShouldDispatchAfterCommit`, `failed()` logs
`chat_broadcast_failed`. `typing`: tries 1. Broadcasts are best effort (no outbox): two workers may deliver out of
order, so clients order by `seq`.

## Client reconnect algorithm

1. On close/error, reconnect with exponential backoff and full jitter: `delay = random(0, min(30 s, 1 s * 2^attempt))`;
   reset `attempt` after a connection has been stable for 60 s.
2. If the access token expires within the next minute (or a channel auth answered 401), refresh it first
   (`POST /api/v1/auth/refresh`), then re-authorize. Re-authorize after every token refresh anyway (see limitations).
3. Resubscribe every channel (one `POST /api/broadcasting/auth` per channel, with the new `socket_id`).
4. For each open conversation call `GET .../messages?after_seq=<last seen seq>&limit=200` repeatedly until
   `has_more = false`.
5. Merge by `(conversation_id, seq)`: drop duplicates, insert in seq order. If a live event arrives with
   `seq > last_seen + 1`, a broadcast was lost or reordered: run step 4 for that conversation.
6. Resend messages that never got a 2xx with the **same** `client_message_id` (200 = it was stored already;
   409 `client-message-id-reuse` = your local copy differs: show an error).
7. Pusher close / error codes: `4000-4099` -> do **not** reconnect (e.g. 4001 app disabled, 4009 origin not allowed:
   fix configuration); `4100-4199` -> reconnect with backoff (4100 over capacity / connection limit); `4200-4299` ->
   reconnect immediately (4200 generic, 4201 pong not received). Any other close (network, 1006) -> backoff.
   A channel auth 403 is final for that channel; 401 -> refresh token then retry; 429 / 503 -> backoff.

## Size arithmetic (Reverb limits)

A 2000-character Persian body is 4000 bytes of UTF-8 (2 bytes per character). The publish path is larger:

1. pusher-php-server 7.3 `json_encode`s the event data without `JSON_UNESCAPED_UNICODE`: each character becomes `\uXXXX`
   (6 bytes) -> 12 000 bytes for the body;
2. it then `json_encode`s the whole request body (`{"name","data","channels"}`), escaping the backslash of each
   `\uXXXX`: **7 bytes per character -> 14 000 bytes**, plus the envelope (ids, timestamps, channel), ~ 0.5 KB.

That exceeds Reverb's default 10 000-byte `max_request_size` (HTTP API) and would also exceed a 10 000
`max_message_size`. Defaults are therefore `REVERB_MAX_REQUEST_SIZE=32768` and `REVERB_APP_MAX_MESSAGE_SIZE=32768`
(about 2x headroom). `ChatMessagingTest` builds the exact publish request and subscriber frame from a persisted max-length
Persian message, asserts it is > 10 000 and within the configured limits, and prints the sizes:
`[chat size] publish request N bytes, subscriber frame M bytes` (measured with `make test` on the dev stack, 2026-11-08: publish request 14 479 bytes, subscriber frame 14 477 bytes, both under the 32 768 limits).
If `CHAT_BODY_MAX_CHARS` is raised, raise both limits to at least `7 x chars + 1024`.

## Origin check

Reverb 1.12 (`Protocols/Pusher/Server::verifyOrigin`) compares only the **host** of the `Origin` header
(`parse_url(..., PHP_URL_HOST)`) with each `allowed_origins` entry using `Str::is` wildcards: scheme and port are
ignored (`localhost` allows `http://localhost:5173` and `https://localhost:8443`). Rejected connections receive
`pusher:error` code 4009 and are closed. `REVERB_ALLOWED_ORIGINS` (comma-separated hosts) defaults to the hosts of
`CORS_ALLOWED_ORIGINS`. `make verify-chat` asserts the SPA origin is accepted and `Origin: https://evil.example` refused.

## Connection cap (approximate)

Reverb has no disconnect hook, so the per-user cap is enforced at channel auth: a sorted set `chat:conn:{user}` of
distinct `socket_id`s (score = last seen) is pruned and checked in one Lua script (two parallel auths cannot both take
the last slot). Max = `rate-limits.concurrency.chat-connections.max` (`CHAT_CONNECTIONS_PER_USER`, default 5,
`RATE_LIMIT_MULTIPLIER` applies), slot lifetime `CHAT_CONNECTION_TTL_MS` (120 s). Limits of the approximation:
sockets that never authorize a private channel are invisible; a slot stays alive only while the heartbeat
(`POST /presence/heartbeat {socket_id}`) refreshes it, so a closed socket frees its slot after the TTL, and a live
socket whose client stops heart-beating may be "forgotten" (it keeps working; it just no longer counts). Fails closed.

## Known limitation: token revocation

A private subscription lives until the socket closes. Logging out or revoking a token does **not** kick an active
socket. Mitigation: short access-token TTL; clients re-authorize their channels after every token refresh;
`agents.queue`/conversation access is re-checked on every (re)subscribe. Listed under Open decisions.

## Scaling

- `REVERB_SCALE=2 make up` starts two replicas (`--scale reverb=N`; the service has no container_name and no host
  ports). `REVERB_SCALING_ENABLED=true` makes every replica subscribe to the Redis channel `REVERB_SCALING_CHANNEL`.
- The scaling block reads `REDIS_HOST` / `REDIS_DB` (**not** the `state` connection): in Docker that is `redis-queue`,
  db 0, i.e. the `default` connection's server (noeviction). Pub/sub messages are not stored, so they cost no memory there.
- Traefik's file provider points at `http://reverb:8080`; Docker's DNS returns every replica and each new WebSocket
  dial resolves again. The distribution is therefore whatever DNS ordering yields, **not** guaranteed round robin.
  `make verify-chat` prints ESTABLISHED sockets per replica while k6 holds idle sockets. Measured on 2026-11-08 with ONE replica: 19 ESTABLISHED sockets on `reverb-1` at the sampling moment; the split
  across two replicas is NOT measured yet (`REVERB_SCALE=2 make up && make verify-chat` was not run). Cross-replica fan-out is proven when every subscribed socket receives `message.sent` while the
  split shows sockets on both replicas.
- `max_connections` (`REVERB_APP_MAX_CONNECTIONS`) is enforced **per Reverb instance**, never globally.

## Connection ceiling per replica

ReactPHP's default `StreamSelectLoop` is limited by `FD_SETSIZE` = 1024 descriptors per process. The Dockerfile
attempts `install-php-extensions uv` (ext-uv lifts the limit; ReactPHP picks `ExtUvLoop` automatically) but does not
fail the build when php-uv does not compile on PHP 8.5. `make reverb-loop` prints the loop actually used.
On the dev stack (2026-11-08) it printed `React\EventLoop\ExtUvLoop`, so the 1024 limit does not apply there.
If it prints `StreamSelectLoop`, plan for < ~1000 sockets per replica and scale out with `REVERB_SCALE`
(TODO(measure): ceiling observed by Task 10). The compose file raises `nofile` to 65 536 for the uv case.

## Delivery latency (measured)

`make verify-chat` (one Reverb replica, 5 subscribed sockets, the agent posts by REST, time until every socket has `message.sent`):
with Horizon's default 3 s polling of an idle queue it took 727 ms and 1848 ms, and one run missed the 2 s limit; the job
itself ran in 5 to 36 ms, so the wait was queue pickup. After `supervisor-broadcasts` got `sleep` = 1 s
(`HORIZON_BROADCASTS_SLEEP`) six runs took 163 to 778 ms. Small samples, one machine: a sanity check, not a benchmark.

## Hand-off notes

- **Task 9 (metrics)**: subscribe to `Modules\Chat\Events\MessagePersisted`, `ConversationClaimed`, `ConversationClosed`
  (after commit, ids only) - Chat needs no change. Connection count: Reverb exposes the Pusher-compatible
  `GET /apps/{app_id}/connections` (signed) on `reverb:8080` over the backend network; with scaling enabled Reverb
  gathers it from all replicas through pub/sub. `make verify-chat` step 4 calls it via the app's Pusher client
  (measured 2026-11-08: the call works and returned `{"connections":5}` while k6 held 19 ESTABLISHED sockets, 5 of them subscribed to the private channel; the two numbers were sampled a few seconds apart. The count therefore looks like channel-subscribed connections, not every open socket. That is observed, not verified in Reverb's source: do not use it as a socket count).
- **Task 10 (load)**: `php artisan chat:verify-invariants --rebuild` after a run (non-zero exit on violation).
  Knobs: `CHAT_CONNECTIONS_PER_USER`, `CHAT_CONNECTION_TTL_MS`, `CHAT_*_RATE`, `RATE_LIMIT_MULTIPLIER`,
  `REVERB_APP_MAX_CONNECTIONS` (per replica), `REVERB_SCALE`, `CHAT_AGENT_MAX_OPEN`; per-replica ceiling above.
  `loadtests/chat-smoke.js` is the starting point.
- **Task 11 (TLS)**: clients switch to `wss` (`VITE_REVERB_SCHEME=https`, port 443) while the app keeps publishing over
  http to `reverb:8080`; `/apps/` stays unrouted; tune Reverb `stop_grace_period` (15 s now) with the deploy strategy.
