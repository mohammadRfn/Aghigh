<?php

declare(strict_types=1);

return [
    'paths' => ['api/*'], // covers api/broadcasting/auth (Task 7 route); a bare broadcasting/auth path does not exist
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // Explicit list only: credentials (refresh cookie) are not allowed with "*".
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Request-Id', 'Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset'],
    'max_age' => 600,
    'supports_credentials' => true,
];
