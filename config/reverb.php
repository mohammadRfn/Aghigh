<?php

declare(strict_types=1);

/*
 | Task 8: Reverb (laravel/reverb 1.12).
 |
 | Two different addresses, never mixed:
 | - SERVER-SIDE publishing (app / horizon -> Reverb HTTP API): REVERB_HOST=reverb, REVERB_PORT=8080, REVERB_SCHEME=http,
 |   over the internal backend network. Used by config/broadcasting.php and by apps[].options below.
 | - CLIENTS (browsers, k6): VITE_REVERB_HOST=localhost, VITE_REVERB_PORT=8000, VITE_REVERB_SCHEME=http, path /app,
 |   through Traefik. Traefik routes only /app/ (WebSocket); /apps/ (signed HTTP API) is not routed.
 |
 | `hostname` (servers.reverb.hostname) is only used by Reverb to pick a TLS certificate; it is NOT the publish target,
 | so it no longer reads REVERB_HOST (that would point at "reverb").
 */

$csv = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== ''));

// Reverb 1.12 compares only the HOST of the Origin header (parse_url(..., PHP_URL_HOST)) against each entry with
// Str::is() wildcards: scheme and port are ignored. Default = hosts of CORS_ALLOWED_ORIGINS. See docs/chat-protocol.md.
$allowedOrigins = $csv(env('REVERB_ALLOWED_ORIGINS'));
if ($allowedOrigins === []) {
    $allowedOrigins = array_values(array_unique(array_filter(array_map(
        static fn (string $origin): ?string => parse_url($origin, PHP_URL_HOST) ?: null,
        $csv(env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
    ))));
}

return [

    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_SERVER_HOSTNAME'),
            'options' => [
                'tls' => [],
            ],
            // Body limit of Reverb's HTTP API (the app's publish request). A max-length Persian body is ~7 bytes per
            // character on that request (\uXXXX escaped twice): 2000 x 7 = 14 000 bytes + envelope > 10 000 default.
            // 32 768 leaves room; ChatPayloadSizeTest measures the real request. docs/chat-protocol.md "Size arithmetic".
            'max_request_size' => (int) env('REVERB_MAX_REQUEST_SIZE', 32_768),
            // Horizontal scaling: every replica subscribes to one Redis pub/sub channel. This block reads REDIS_HOST /
            // REDIS_DB (NOT the `state` connection): in Docker REDIS_HOST=redis-queue, REDIS_DB=0 -> the `default`
            // connection's server (noeviction, AOF). Pub/sub is not stored, so the db number only scopes nothing;
            // the channel name REVERB_SCALING_CHANNEL isolates environments sharing a Redis.
            'scaling' => [
                'enabled' => (bool) env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                // Server-side publishing target (same as config/broadcasting.php), not the client address.
                'options' => [
                    'host' => env('REVERB_HOST', 'reverb'),
                    'port' => (int) env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                'allowed_origins' => $allowedOrigins,
                'ping_interval' => (int) env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => (int) env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                // PER REVERB INSTANCE (each replica counts its own sockets), not global once REVERB_SCALE > 1.
                // Empty = unlimited (bounded by the event loop / file descriptors, docs/chat-protocol.md).
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS') !== null && env('REVERB_APP_MAX_CONNECTIONS') !== ''
                    ? (int) env('REVERB_APP_MAX_CONNECTIONS') : null,
                // Largest frame a CLIENT may send. Clients only send pusher:subscribe / ping (no client events), but the
                // value is kept >= the publish size so a future echo of a max message can never trip it.
                'max_message_size' => (int) env('REVERB_APP_MAX_MESSAGE_SIZE', 32_768),
                // Clients never send client events (typing goes through REST + a throttled server broadcast).
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none'),
                'rate_limiting' => [
                    'enabled' => (bool) env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => (int) env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => (int) env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => (bool) env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
