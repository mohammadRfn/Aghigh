<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | Rate limiting (Task 7)
 |--------------------------------------------------------------------------
 | Every counter lives on the `state` Redis (noeviction). Numbers below are
 | CONFIGURATION, not measurements: docs/rate-limiting.md explains the reasoning.
 |
 | Each named limiter:
 |   limit   requests allowed per window (scaled by `multiplier`, never disabled)
 |   window  seconds
 |   by      key strategy: ip | user | user+ip | ip+email
 |   driver  laravel (fixed window, Illuminate RateLimiter) | sliding (sliding log in Lua)
 |   fail    open | closed  -> behaviour when the limiter backend (Redis) errors
 |
 | Lockout (identity.lockout.*) is a SEPARATE per-account mechanism in the database.
 | The `auth-login` limiter throttles attempts per (IP, email tag) before the password
 | is ever checked; lockout counts failed passwords per account regardless of IP.
 | Both stay; neither replaces the other.
 */

return [

    // Raw env value; validated by Modules\Shared\RateLimiting\RateLimitMultiplier.
    // Honoured ONLY in local/testing/loadtest (UnsafeEnvironment). Elsewhere boot fails if it is set.
    'multiplier' => env('RATE_LIMIT_MULTIPLIER'),

    // redis (default) | memory (Feature suite: `make test` has no Redis).
    'sliding_driver' => env('RATE_LIMIT_SLIDING_DRIVER', 'redis'),

    // Hard cap for limiters on the `sliding` driver: a sorted set holds up to `limit` members per key,
    // so limit x active keys bounds the memory on the noeviction state Redis (docs/rate-limiting.md).
    'sliding_max_limit' => (int) env('RATE_LIMIT_SLIDING_MAX_LIMIT', 1000),

    // Truncated HMAC(app.key) prefix length used for keys in logs/events. Never a plain hash of an email.
    'hash_length' => 16,

    'log' => [
        'channel' => 'security',
        // First N hits per (limiter, key) per window are logged individually ...
        'sample_after' => (int) env('RATE_LIMIT_LOG_SAMPLE_AFTER', 20),
        // ... then one line every M hits with a `suppressed` count.
        'sample_every' => (int) env('RATE_LIMIT_LOG_SAMPLE_EVERY', 100),
    ],

    'limiters' => [

        // ---- auth (per IP, pre-authentication). Task 3 values and keys preserved.
        // POST /api/v1/auth/login
        'auth-login' => ['limit' => (int) env('AUTH_LOGIN_RATE', 10), 'window' => 60, 'by' => 'ip+email', 'driver' => 'laravel', 'fail' => 'closed'],
        // POST /api/v1/auth/register, GET /api/v1/auth/email/verify/{id}/{hash}
        'auth-register' => ['limit' => (int) env('AUTH_REGISTER_RATE', 5), 'window' => 60, 'by' => 'ip', 'driver' => 'laravel', 'fail' => 'closed'],
        // POST /api/v1/auth/forgot-password, POST /api/v1/auth/reset-password
        'auth-forgot-password' => ['limit' => (int) env('AUTH_FORGOT_RATE', 5), 'window' => 60, 'by' => 'ip', 'driver' => 'laravel', 'fail' => 'closed'],
        // POST /api/v1/auth/email/resend
        'auth-email-resend' => ['limit' => (int) env('AUTH_RESEND_RATE', 5), 'window' => 60, 'by' => 'ip', 'driver' => 'laravel', 'fail' => 'closed'],
        // POST /api/v1/auth/refresh (cookie, pre-auth)
        'auth-refresh' => ['limit' => (int) env('AUTH_REFRESH_RATE', 30), 'window' => 60, 'by' => 'ip', 'driver' => 'laravel', 'fail' => 'closed'],

        // ---- orders. Pre-auth per-IP shield, then per-user sliding log (the only `sliding` limiter).
        // POST/GET /api/v1/orders, GET /api/v1/orders/{order}, POST /api/v1/orders/{order}/cancel
        'orders-ip' => ['limit' => (int) env('ORDERS_IP_RATE', 120), 'window' => 60, 'by' => 'ip', 'driver' => 'laravel', 'fail' => 'closed'],
        'orders' => ['limit' => (int) env('ORDERS_USER_RATE', 30), 'window' => 60, 'by' => 'user', 'driver' => 'sliding', 'fail' => 'closed'],

        // ---- chat (Task 8 attaches it to POST /api/v1/chat/conversations/{id}/messages)
        'chat-messages' => ['limit' => (int) env('CHAT_MESSAGES_RATE', 60), 'window' => 60, 'by' => 'user', 'driver' => 'laravel', 'fail' => 'closed'],

        // ---- public catalog (per IP). Reads are cached and harmless: fail OPEN.
        // GET /api/v1/products, /api/v1/products/{identifier}, /api/v1/categories
        'public-catalog' => ['limit' => (int) env('CATALOG_PUBLIC_RATE', 300), 'window' => 60, 'by' => 'ip', 'driver' => 'laravel', 'fail' => 'open'],

        // ---- admin (per user): /api/v1/admin/*, /api/v1/users/{user}/role, inventory admin
        'admin' => ['limit' => (int) env('ADMIN_RATE', 120), 'window' => 60, 'by' => 'user', 'driver' => 'laravel', 'fail' => 'closed'],

        // ---- payment webhook (per IP, generous; the HMAC signature is the real gate). POST /api/v1/payments/webhook
        'webhook' => ['limit' => (int) env('WEBHOOK_RATE', 600), 'window' => 60, 'by' => 'ip', 'driver' => 'laravel', 'fail' => 'open'],

        // ---- broadcasting auth (per user + IP). POST /api/broadcasting/auth
        'broadcasting-auth' => ['limit' => (int) env('BROADCASTING_AUTH_RATE', 60), 'window' => 60, 'by' => 'user+ip', 'driver' => 'laravel', 'fail' => 'closed'],
    ],

    // Per-user concurrent in-flight requests (Lua semaphore). ttl_ms MUST exceed the longest request
    // (Octane max_execution_time / PgBouncer lock_timeouts) so a crashed worker's slot expires on its own.
    'concurrency' => [
        'orders' => ['max' => (int) env('ORDERS_CONCURRENCY_MAX', 2), 'ttl_ms' => (int) env('ORDERS_CONCURRENCY_TTL_MS', 30000), 'fail' => 'closed'],
    ],

    // Business limit inside PlaceOrder (NOT the HTTP limiter): attempts per user per minute, counted in Redis
    // outside the DB transaction, after the idempotency replay check. A 409 attempt consumes budget.
    'business' => [
        'orders_per_minute' => (int) env('ORDERS_PER_MINUTE_PER_USER', 10),
    ],
];
