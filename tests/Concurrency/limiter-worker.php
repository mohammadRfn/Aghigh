<?php

declare(strict_types=1);

use Modules\Shared\RateLimiting\RedisConcurrencyLimiter;
use Modules\Shared\RateLimiting\RedisSlidingWindowLimiter;
use Tests\Support\Race;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Modes:
//   attempt <key> <limit> <windowSeconds>          one sliding-window attempt against the REAL Lua limiter
//   hold <key> <max> <ttlMs> <holdMs>               acquire a semaphore slot, hold it, release it
Race::worker($argv, function (array $arguments): array {
    [$mode, $key] = $arguments;
    $redis = app('redis');

    if ($mode === 'attempt') {
        $result = (new RedisSlidingWindowLimiter($redis, 'state'))->attempt($key, (int) $arguments[2], (int) $arguments[3]);

        return ['allowed' => $result->allowed, 'remaining' => $result->remaining, 'retry_after_ms' => $result->retryAfterMs];
    }

    $semaphore = new RedisConcurrencyLimiter($redis, 'state');
    $member = 'w-'.getmypid().'-'.bin2hex(random_bytes(4));
    $acquired = $semaphore->acquire($key, $member, (int) $arguments[2], (int) $arguments[3]);
    $observed = $acquired ? $semaphore->inFlight($key) : 0;
    if ($acquired) {
        usleep((int) $arguments[4] * 1000);
        $semaphore->release($key, $member);
    }

    return ['acquired' => $acquired, 'observed_in_flight' => $observed];
});
