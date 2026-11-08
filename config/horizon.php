<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 * Task 6 topology (docs/queues.md): one supervisor per queue, auto-balanced between env-tunable
 * min/max processes. Budgets (configuration, not measurements):
 * - every timeout below and every job $timeout must stay < queue.connections.redis.retry_after (120 s);
 *   tests/Feature/QueueTopologyTest.php enforces it;
 * - sum(maxProcesses) + Octane workers + Reverb + scheduler must fit the PgBouncer pool (compose);
 * - sum(maxProcesses + supervisors + master) x memory must fit the horizon container mem_limit.
 */
$supervisor = static function (string $queue, int $min, int $max, int $timeout, int $maxJobs, int $maxTime, int $sleep = 3): array {
    $prefix = 'HORIZON_'.strtoupper($queue).'_';

    return [
        'connection' => 'redis',
        'queue' => [$queue],
        'balance' => 'auto',
        'autoScalingStrategy' => 'time',
        'minProcesses' => (int) env($prefix.'MIN_PROCESSES', $min),
        'maxProcesses' => (int) env($prefix.'MAX_PROCESSES', $max),
        'balanceMaxShift' => 1,
        'balanceCooldown' => 3,
        'sleep' => (int) env($prefix.'SLEEP', $sleep), // seconds between polls of an EMPTY queue
        // Recycle workers: bounded leaks and fresh code without a deploy-time terminate.
        'maxJobs' => (int) env($prefix.'MAX_JOBS', $maxJobs),
        'maxTime' => (int) env($prefix.'MAX_TIME', $maxTime),
        // MB of PHP heap after which a worker (and the supervisor itself) restarts between jobs.
        'memory' => (int) env('HORIZON_WORKER_MEMORY', 64),
        // Defaults for jobs that declare nothing (queued mail, broadcasts, listeners).
        'tries' => 3,
        'timeout' => $timeout,
        'backoff' => [10, 30, 90],
        'nice' => 0,
    ];
};

$supervisors = [
    'supervisor-orders' => $supervisor('orders', 1, 4, 60, 1000, 3600),
    'supervisor-broadcasts' => $supervisor('broadcasts', 1, 2, 30, 2000, 3600, 1),
    'supervisor-notifications' => $supervisor('notifications', 1, 2, 60, 500, 3600),
    'supervisor-default' => $supervisor('default', 1, 2, 100, 500, 3600),
];
$enabled = array_map(static fn (): array => [], $supervisors);

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    // Dashboard: local environment only (App\Providers\HorizonServiceProvider); Task 11 also blocks it at the proxy.
    'middleware' => ['web'],

    // LongWaitDetected thresholds in seconds per connection:queue.
    'waits' => [
        'redis:orders' => 30,
        'redis:broadcasts' => 10,
        'redis:notifications' => 120,
        'redis:default' => 120,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    // MB for the master process.
    'memory_limit' => 64,

    'defaults' => $supervisors,

    // Every environment runs all four supervisors with the defaults above ("*" catches the rest).
    'environments' => [
        'production' => $enabled,
        'loadtest' => $enabled,
        'local' => $enabled,
        '*' => $enabled,
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
