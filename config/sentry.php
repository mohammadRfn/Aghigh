<?php

declare(strict_types=1);

use Modules\Shared\ErrorReporting\SentryScrubber;

/*
 | Task 9: Sentry SDK -> GlitchTip (self-hosted, compose profile `observability`). Published from sentry-laravel 4.x
 | and trimmed. Empty DSN = no-op. Only 5xx and job failures are sent (SentryScrubber::beforeSend drops client
 | errors and scrubs with the SAME key list as the log redactor). No closures here: config:cache must work.
 */
return [

    // In-container DSN: the host part is `glitchtip:8000` (backend network), not the browser address.
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // Git SHA of the running code (set GIT_SHA at build/deploy time; empty = no release attached).
    'release' => env('SENTRY_RELEASE', env('GIT_SHA')) ?: null,

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // Error events: every reported 5xx / failed job (1.0). Lower only under a real flood.
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),

    // Performance tracing: provisional default 0.0 (off); never above 0.05 (Task 10 measures the cost).
    'traces_sample_rate' => min(0.05, (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0)),

    'profiles_sample_rate' => 0.0,

    'enable_logs' => false,

    // Never send IPs, cookies, user emails or request bodies by default; the scrubber removes them anyway.
    'send_default_pii' => false,

    'before_send' => [SentryScrubber::class, 'beforeSend'],

    'ignore_exceptions' => [],

    'ignore_transactions' => [],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false, // bindings could contain PII; the slow_query log channel has what we need
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => true,
        'default_integrations' => true,
    ],

];
