<?php

declare(strict_types=1);

$kid = (string) env('JWT_ACTIVE_KID', 'k1');
$retired = json_decode((string) env('JWT_VERIFICATION_KEYS', '{}'), true, 512, JSON_THROW_ON_ERROR);

return [
    'name' => 'Identity',
    'frontend_url' => env('AUTH_FRONTEND_URL', 'http://localhost:5173'),
    'jwt' => [
        'algorithm' => 'EdDSA', // Pinned server-side, never negotiated from a token.
        'issuer' => env('JWT_ISSUER', 'http://localhost:8000'),
        'audience' => env('JWT_AUDIENCE', 'http://localhost:5173'),
        'ttl' => (int) env('JWT_ACCESS_TTL', 10),
        'kid' => $kid,
        'private_key' => env('JWT_PRIVATE_KEY') ?: '@'.base_path((string) env('JWT_PRIVATE_KEY_PATH', 'storage/jwt/k1.private')),
        'verification_keys' => array_merge(is_array($retired) ? $retired : [], [
            $kid => env('JWT_PUBLIC_KEY') ?: '@'.base_path((string) env('JWT_PUBLIC_KEY_PATH', 'storage/jwt/k1.public')),
        ]),
    ],
    'dummy_hash_path' => storage_path('jwt/dummy.hash'),
    'dummy_hash' => null,
    'refresh' => [
        'cookie' => 'aghigh_refresh',
        'path' => '/api/v1/auth',
        'grace' => (int) env('REFRESH_REUSE_GRACE_SECONDS', 5),
        'idle_days' => (int) env('REFRESH_IDLE_DAYS', 14),
        'absolute_days' => (int) env('REFRESH_ABSOLUTE_DAYS', 30),
    ],
    'lockout' => [
        'attempts' => (int) env('AUTH_LOCKOUT_ATTEMPTS', 5),
        'base' => (int) env('AUTH_LOCKOUT_BASE_SECONDS', 30),
        'max' => (int) env('AUTH_LOCKOUT_MAX_SECONDS', 900),
    ],
    'rates' => [
        'login' => (int) env('AUTH_LOGIN_RATE', 10),
        'register' => (int) env('AUTH_REGISTER_RATE', 5),
        'forgot-password' => (int) env('AUTH_FORGOT_RATE', 5),
    ],
    'reset_ttl' => (int) env('AUTH_RESET_TTL_MINUTES', 60),
];
