# OWASP API Security Top 10 (2023): Identity control map

Scope: the Task 3 source implementation in this package. **Tests are supplied but have not been run in the target PHP 8.5 / Docker stack.** “Present” below describes source, not proven effectiveness or project-wide compliance. The release must not be represented as OWASP-certified, CI-green, benchmarked or production-ready.

| Risk | Present control and evidence | Remaining scope |
| --- | --- | --- |
| API1 Broken Object Level Authorization | UserController checks self/admin before lookup; AuthorizationTest includes anonymous and missing-target cases. | Chat (Task 8): every conversation endpoint resolves the row through `ConversationPolicy` (customer = own, agent = assigned, admin = none); unknown, malformed and foreign ids answer the same 404 (REST) / 403 (channel auth). BOLA matrix in `ChatAuthorizationTest`. Other business modules are outside this review. |
| API2 Broken Authentication | Argon2id, dummy-hash checks, verified email, pinned EdDSA, required claims, key rotation, SQL refresh rotation and state denylist; AuthenticationTest and TokenAttacksTest. | Timing measured locally (Docker/WSL, 30 requests each, lockout and login rate limit temporarily raised): median 34 ms for an existing email with a wrong password vs 32 ms for an unknown email. Single local run, not a production benchmark. Production key custody and TLS are operator responsibilities. |
| API3 Broken Object Property Level Authorization | Registration explicitly selects name/email/password; no role mass assignment. Profile output uses an explicit field allowlist. Role changes require admin. | Review new DTO fields as later modules are added. |
| API4 Unrestricted Resource Consumption | Named limiters for every public surface (config/rate-limits.php) on state Redis with RateLimit-* headers, per-user sliding log + concurrency cap on orders, business orders-per-minute budget, bounded sorted-set memory, capped lockout, bounded prune batches, job timeout below retry_after. | Traefik-level coarse shield: planned (Task 11). Queue backpressure: Task 6. |
| API5 Broken Function Level Authorization | Admin-only role endpoint and deliberately tested status matrix; roles are read live, not trusted in JWT claims. | Done in Task 8: private channels `conversation.{id}` / `agents.queue` authorized via CurrentUser + ConversationPolicy behind `auth:api` on the single `/api/broadcasting/auth` route; role checks on open/claim/release; denials logged `chat_channel_denied` (security, hashed ids). Limitation: an active socket survives token revocation until it closes (docs/chat-protocol.md). |
| API6 Unrestricted Access to Sensitive Business Flows | Enumeration-safe account recovery/registration, per-IP/per-user limits, orders-per-minute business budget that replays cannot bypass, one-use reset tokens and refresh-reuse handling. | Done in Task 8: `chat-messages` attached (auth:api -> throttle), plus `chat-claim`, `chat-typing`, `chat-heartbeat`, `chat-read` and an approximate per-user WebSocket cap at channel auth; message bodies are never logged (`body` redacted as a second line of defence). |
| API7 Server Side Request Forgery | Identity does not fetch a user-provided URL. Password validation uses a bundled local list. | No claim is made for other modules or future outbound integrations. |
| API8 Security Misconfiguration | Git-ignored key material, strict refresh cookie scope, exact Origin validation, no session auth, noeviction state dependency and fail-closed behavior. Shared security headers/problem rendering are preserved. | Tight Reverb allowed origins: planned (Task 8). Verify actual deployed proxy/TLS/Redis settings. |
| API9 Improper Inventory Management | Versioned module routes, method documentation for Scramble, endpoint inventory and ADR 0003. | Scramble /docs/api rendering must be checked in the target container. |
| API10 Unsafe Consumption of APIs | No third-party identity provider or external password-check API. Strict token validation and explicit SMTP/outbox boundaries. | Other provider integrations are outside this module; no blanket compliance claim. |

## Security events

`security`: login_failed, lockout, refresh_reuse_detected, password_reset_requested, password_reset_completed, identity_mail_failed.
`audit`: user_role_changed. Log user/family/outbox UUIDs and a short keyed email hash, never passwords, tokens or full email addresses. Mail recipients necessarily use email addresses for delivery; they are not added to logs or queue payloads.

## Before release

Run `make lint stan test test-int`, inspect generated `/docs/api`, prove clean `make fresh-seed` plus register/verify/login/refresh/logout/reset in Mailpit, and obtain a green CI run. Review key permissions and retention, APP_DEBUG=false, actual HTTPS, CORS allowlists and the non-evicting state Redis. Do not erase state Redis as if it were a disposable cache.

## Observability surface (Task 9)
- `/internal/*` is never served from the edge: `docker/traefik/dynamic.yml` router `internal-deny` (priority 100,
  `ipAllowList` with an unroutable range -> 403) sits above `app`; `make obs-verify` proves it with and without a
  valid token. Prometheus scrapes `app:8000/internal/metrics` on the internal `backend` network with a bearer
  token (`METRICS_TOKEN`, constant-time compare; empty token = 404, never open). `/internal/metrics` has no limiter
  and no session: it is not reachable by clients, only by the scraper. `/horizon` is Task 11.
- Metric labels can never carry PII: route NAME or URI template, method, status class, queue, job class, limiter
  NAME, outcome, role. Never ids, emails, IPs, raw URLs or request ids (tests/Arch + MetricsEndpointTest cardinality).
- Error reports (Sentry SDK -> GlitchTip): `send_default_pii=false`; `SentryScrubber::beforeSend` removes the request
  body, query string and cookies, redacts headers/extra/contexts/tags/breadcrumbs/exception messages with the SAME
  key list as the log redactor (`Modules\Shared\Logging\SensitiveKeys`), keeps only the user id, drops every client
  error (4xx / `ProblemException` below 500) so only 5xx and job failures leave the app. Tested in SentryScrubTest.
- Loki labels are `service`, `channel`, `level` only; `request_id` is structured metadata. Log lines are the app's
  redacted JSON; Traefik's access log keeps the response `X-Request-Id` and the path (never bodies or cookies).
- Grafana Alloy mounts the Docker socket read-only. That is still root-equivalent on the host (the API lets a client
  start privileged containers; `:ro` only protects the inode). Dev-only; the documented alternative is a socket
  proxy that exposes only `GET /containers/*` (docs/observability.md).
- The observability UIs (Grafana, Prometheus, GlitchTip, Loki API) bind to 127.0.0.1 only; Grafana has no anonymous
  access and a generated admin password (`make obs-keys`); exporters are reachable only on `backend`.
