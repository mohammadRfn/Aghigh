<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Modules\Shared\Support\UnsafeEnvironment;
use RuntimeException;

/**
 * RATE_LIMIT_MULTIPLIER scales every limit (ceil(limit x multiplier), min 1) for load tests and suites.
 * Honoured only in UnsafeEnvironment::ENVIRONMENTS (local, testing, loadtest). Anywhere else the value is
 * ignored by `factor()` AND `assertAllowed()` fails the boot, so a leaked env line can never weaken production.
 * Limits are scaled, never disabled: the factor is clamped to [0.01, 10000].
 */
final class RateLimitMultiplier
{
    public const MIN = 0.01;

    public const MAX = 10000.0;

    public static function factor(string $environment, mixed $raw): float
    {
        if ($raw === null || $raw === '' || ! UnsafeEnvironment::allows($environment)) {
            return 1.0;
        }
        if (! is_numeric($raw)) {
            throw new RuntimeException('RATE_LIMIT_MULTIPLIER must be numeric, got '.get_debug_type($raw).'.');
        }

        return min(self::MAX, max(self::MIN, (float) $raw));
    }

    public static function scale(int $limit, float $factor): int
    {
        return max(1, (int) ceil($limit * $factor));
    }

    /** Boot check: a multiplier outside the allowed environments is a misconfiguration, not a no-op. */
    public static function assertAllowed(string $environment, mixed $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        if (! UnsafeEnvironment::allows($environment)) {
            throw new RuntimeException(sprintf(
                'RATE_LIMIT_MULTIPLIER is set but APP_ENV=%s is not one of [%s]. Remove it.',
                $environment,
                implode(', ', UnsafeEnvironment::ENVIRONMENTS),
            ));
        }
        self::factor($environment, $raw); // validates the number
    }
}
