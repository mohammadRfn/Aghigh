# ADR 0003: Identity, asymmetric JWTs and rotating refresh families

Status: proposed — implementation supplied; target-container validation is pending.

## Context

Read ADR 0001 and 0002 first. Identity owns UUIDv7 users. Other modules receive only the immutable `Modules\Identity\Contracts\CurrentUser` DTO. No cross-module user model or user foreign key is allowed. Cache eviction must not undo a revocation.

## Decision

### Access tokens and key rotation

Use `lcobucci/jwt` 5.x with Ed25519 / EdDSA, pinned in server configuration. Never select the signer using an untrusted `alg` header. Require a known `kid`, a valid signature, `iss`, `aud`, UUID `sub`, UUID `jti`, `iat`, `nbf` and `exp`. Access TTL defaults to 10 minutes.

Keys are raw Sodium keys, **not PEM**. `identity:jwt-keys --kid=k1` writes them into the git-ignored, mode-0700 `storage/jwt/` directory with mode-0600 files. Optional `JWT_PRIVATE_KEY` and `JWT_PUBLIC_KEY` contain base64-encoded raw key bytes. The command also produces a dummy Argon2id hash; regenerate it with `--refresh-dummy` after changing Argon settings.

Rotation: generate a new kid without overwriting the old pair; distribute its public key to every verifier; keep the old public key in the `JWT_VERIFICATION_KEYS` JSON map; switch active kid/private/public paths; reload Octane and workers. Keep the retired verification key for at least the maximum access lifetime plus deployment overlap. Remove it only when old access tokens have expired. Never put private keys in the verification-key map. CI/image checks require Sodium and Argon2id; actual image build has not been run in this delivery environment. No ES256 fallback is silently enabled.

JWTs carry **no role**. The guard makes one indexed user lookup and reads the current role. Request rebinding clears the guard's cached user. CurrentUser is scoped, not a singleton. API and broadcasting authentication have no session or cookie dependency.

### Durable token ledger and family serialization

`identity_token_families` records absolute expiry/revocation and `identity_access_tokens` records live JTIs. These additional internal tables are intentional: they let password reset, logout and refresh reuse enumerate all affected JWTs. JWT authentication itself does not query this ledger.

All issuance and revocation serialize on the user row. A refresh is first claimed by a conditional `UPDATE ... RETURNING`; child refresh insertion and access-ledger insertion share that transaction. This is not read-then-write in PHP. No session advisory lock is used, so transaction-pooling PgBouncer remains supported. PostgreSQL deadlock/serialization retries are bounded by Laravel's transaction retry count.

Only SHA-256 digests of opaque 256-bit refresh credentials are persisted. Families live for at most 30 days, with 14-day idle expiry (configurable). Each child expiry is capped by the original family deadline.

A consumed token reused within the default five-second grace period returns **401 `refresh-token-conflict`** and does not revoke anything. Do not clear or overwrite the winner's cookie on this response. Clients should coalesce refresh calls; if the winning response was lost, sign in again. After grace, reuse revokes the family and denylists its live JTIs. Revocation commits **before** the reuse exception is thrown. Used-parent tombstones remain until absolute family expiry so pruning does not conceal reuse.

### Redis dependency and failure behavior

Denylist entries use `Cache::store('state')`, never disposable `cache`. Their TTL is the token's remaining lifetime, not the original access TTL. The guard checks state Redis on every authenticated request; failures return a 503 problem and never bypass checks. Issuance and revocation also fail closed. Denylist writes occur before SQL commit; failed SQL may temporarily deny a still-valid token, but cannot silently accept a revoked one. Redis and SQL are not a distributed transaction.

Use noeviction/AOF as prescribed by ADR 0002. Loss of the state dataset is a security incident, not a normal cache miss: stop authentication and rotate signing keys/revoke families before recovery. There is no fallback to the disposable cache.

### Cookies and CSRF

The refresh token lives only in an HttpOnly, SameSite=Strict cookie with Path=/api/v1/auth, no Domain, and Secure in production. It never appears in JSON. Refresh and logout require an exact, non-null Origin from the configured CORS allowlist. Wildcard origins do not pass. Deploy the frontend and API on the same site; merely adding a cross-site origin to CORS does not override SameSite=Strict.

### Passwords, recovery and mail

Use env-tunable Argon2id, a local deny-list, 12–128 characters and no email local-part substring. No external compromised-password service is called. Unknown emails are checked against a precomputed Argon2id dummy hash. Correct passwords are required before exposing unverified-email status. Timing equivalence still requires target-environment measurement: TODO(measure).

Reset tokens are a keyed PRF of a random UUIDv7 reset ID and APP_KEY, domain-separated for password recovery. Only their SHA-256 hash is stored. This allows an ID-only job to reconstruct the same token on retry without storing a plaintext reset secret. Rotation of APP_KEY invalidates pending reset links as well as signed email-verification links.

An outbox row holds mail purpose/user/reset IDs. Jobs carry only the outbox UUID, use `default`, `afterCommit()`, explicit retry/backoff and a failure handler. A state lock and sent marker avoid normal duplicate sends. SMTP cannot provide exactly-once delivery across an acknowledgement crash: duplicate delivery remains possible; the links are idempotent/one-use. This is explicitly not an exactly-once claim. Task 6 will rework queue operations.

## Authorization response policy

For valid UUID routes: anonymous => 401. Authenticated non-admin cross-user reads => 403 before target lookup, whether the target exists or not. Non-admin role changes => 403 before lookup. Authorized missing targets => 404. Self reads and admin reads => 200; role changes are admin-only and audit logged. Malformed UUIDs do not match the route and return 404. The Pest matrix documents these choices.

## Evidence and limitations

Feature and real-process tests are supplied in `Modules/Identity/tests/`. The concurrency helper starts 20 real PHP processes behind a ready/start barrier, never a sequential simulation. No test pass, latency, throughput, coverage percentage, CI success or production readiness is claimed. See `docs/identity-delivery/VALIDATION.md`.
