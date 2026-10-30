# OWASP API Security Top 10 (2023): Identity control map

Scope: the Task 3 source implementation in this package. **Tests are supplied but have not been run in the target PHP 8.5 / Docker stack.** “Present” below describes source, not proven effectiveness or project-wide compliance. The release must not be represented as OWASP-certified, CI-green, benchmarked or production-ready.

| Risk | Present control and evidence | Remaining scope |
| --- | --- | --- |
| API1 Broken Object Level Authorization | UserController checks self/admin before lookup; AuthorizationTest includes anonymous and missing-target cases. | Other business modules are outside this review. |
| API2 Broken Authentication | Argon2id, dummy-hash checks, verified email, pinned EdDSA, required claims, key rotation, SQL refresh rotation and state denylist; AuthenticationTest and TokenAttacksTest. | Timing measured locally (Docker/WSL, 30 requests each, lockout and login rate limit temporarily raised): median 34 ms for an existing email with a wrong password vs 32 ms for an unknown email. Single local run, not a production benchmark. Production key custody and TLS are operator responsibilities. |
| API3 Broken Object Property Level Authorization | Registration explicitly selects name/email/password; no role mass assignment. Profile output uses an explicit field allowlist. Role changes require admin. | Review new DTO fields as later modules are added. |
| API4 Unrestricted Resource Consumption | Named login/register/forgot limits on state Redis, capped lockout, bounded prune batches, job timeout below retry_after. | RateLimit-* headers and wider API limits: planned (Task 7). Queue backpressure: planned (Task 6). |
| API5 Broken Function Level Authorization | Admin-only role endpoint and deliberately tested status matrix; roles are read live, not trusted in JWT claims. | Task 8 channel-specific authorization is planned (Task 8). |
| API6 Unrestricted Access to Sensitive Business Flows | Enumeration-safe account recovery/registration, rate limits, one-use reset tokens and refresh-reuse handling. | Wider business-flow abuse controls: planned (Task 7); identity controls do not protect unimplemented checkout/chat flows. |
| API7 Server Side Request Forgery | Identity does not fetch a user-provided URL. Password validation uses a bundled local list. | No claim is made for other modules or future outbound integrations. |
| API8 Security Misconfiguration | Git-ignored key material, strict refresh cookie scope, exact Origin validation, no session auth, noeviction state dependency and fail-closed behavior. Shared security headers/problem rendering are preserved. | Tight Reverb allowed origins: planned (Task 8). Verify actual deployed proxy/TLS/Redis settings. |
| API9 Improper Inventory Management | Versioned module routes, method documentation for Scramble, endpoint inventory and ADR 0003. | Scramble /docs/api rendering must be checked in the target container. |
| API10 Unsafe Consumption of APIs | No third-party identity provider or external password-check API. Strict token validation and explicit SMTP/outbox boundaries. | Other provider integrations are outside this module; no blanket compliance claim. |

## Security events

`security`: login_failed, lockout, refresh_reuse_detected, password_reset_requested, password_reset_completed, identity_mail_failed.
`audit`: user_role_changed. Log user/family/outbox UUIDs and a short keyed email hash, never passwords, tokens or full email addresses. Mail recipients necessarily use email addresses for delivery; they are not added to logs or queue payloads.

## Before release

Run `make lint stan test test-int`, inspect generated `/docs/api`, prove clean `make fresh-seed` plus register/verify/login/refresh/logout/reset in Mailpit, and obtain a green CI run. Review key permissions and retention, APP_DEBUG=false, actual HTTPS, CORS allowlists and the non-evicting state Redis. Do not erase state Redis as if it were a disposable cache.
