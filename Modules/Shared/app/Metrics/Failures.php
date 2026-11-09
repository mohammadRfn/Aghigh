<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process-local accounting of metric writes that failed. Static on purpose: it must work when the container,
 * the Redis connection or the logger are the things that are broken. Logs at most once per minute per process.
 */
final class Failures
{
    private static int $pending = 0;

    private static int $total = 0;

    private static float $lastLoggedAt = 0.0;

    private static int $suppressed = 0;

    public static function record(string $driver, Throwable $error): void
    {
        self::$pending++;
        self::$total++;

        $now = microtime(true);
        if ($now - self::$lastLoggedAt < 60.0) {
            self::$suppressed++;

            return;
        }
        $suppressed = self::$suppressed;
        self::$lastLoggedAt = $now;
        self::$suppressed = 0;

        try {
            Log::channel('app')->warning('metrics_write_failed', [
                'driver' => $driver,
                'error' => $error::class,
                'message' => mb_substr($error->getMessage(), 0, 200),
                'suppressed' => $suppressed,
                'pending' => self::$pending,
            ]);
        } catch (Throwable) {
            // The logger is not allowed to be the thing that breaks a request either.
        }
    }

    /** Failures not yet flushed to the backend (this process). */
    public static function pending(): int
    {
        return self::$pending;
    }

    /** Failures since this process started. */
    public static function total(): int
    {
        return self::$total;
    }

    /** Returns the pending count and zeroes it; the caller is about to flush it into the backend. */
    public static function take(): int
    {
        $pending = self::$pending;
        self::$pending = 0;

        return $pending;
    }

    /** Puts a failed flush back. */
    public static function restore(int $count): void
    {
        self::$pending += $count;
    }

    public static function reset(): void
    {
        self::$pending = 0;
        self::$total = 0;
        self::$lastLoggedAt = 0.0;
        self::$suppressed = 0;
    }
}
