<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Resolves named limiters from config on every call (no cached per-request state: Octane-safe, and tests
 * may change config between requests). Applies RATE_LIMIT_MULTIPLIER and the sliding-log hard cap.
 */
final class LimiterRegistry
{
    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function get(string $name): LimiterDefinition
    {
        /** @var array<string, mixed>|null $raw */
        $raw = $this->config->get('rate-limits.limiters.'.$name);
        if (! is_array($raw)) {
            throw new InvalidArgumentException("Rate limiter [{$name}] is not defined in config/rate-limits.php.");
        }
        $driver = (string) ($raw['driver'] ?? 'laravel');
        $limit = RateLimitMultiplier::scale((int) ($raw['limit'] ?? 60), $this->factor());
        if ($driver === 'sliding') {
            // Each allowed attempt is one sorted-set member: cap it so limit x keys stays bounded (docs/rate-limiting.md).
            $limit = min($limit, max(1, (int) $this->config->get('rate-limits.sliding_max_limit', 1000)));
        }

        return new LimiterDefinition(
            name: $name,
            limit: $limit,
            windowSeconds: max(1, (int) ($raw['window'] ?? 60)),
            by: (string) ($raw['by'] ?? 'ip'),
            driver: $driver,
            failMode: (string) ($raw['fail'] ?? 'closed'),
        );
    }

    /** @return array{max: int, ttl_ms: int, fail: string} */
    public function concurrency(string $name): array
    {
        /** @var array<string, mixed>|null $raw */
        $raw = $this->config->get('rate-limits.concurrency.'.$name);
        if (! is_array($raw)) {
            throw new InvalidArgumentException("Concurrency cap [{$name}] is not defined in config/rate-limits.php.");
        }

        return [
            'max' => RateLimitMultiplier::scale((int) ($raw['max'] ?? 1), $this->factor()),
            'ttl_ms' => max(1000, (int) ($raw['ttl_ms'] ?? 30000)),
            'fail' => (string) ($raw['fail'] ?? 'closed'),
        ];
    }

    /** Business limits are scaled too, so load tests keep the same proportions. */
    public function business(string $name): int
    {
        return RateLimitMultiplier::scale((int) $this->config->get('rate-limits.business.'.$name, 10), $this->factor());
    }

    public function factor(): float
    {
        return RateLimitMultiplier::factor((string) $this->app->environment(), $this->config->get('rate-limits.multiplier'));
    }
}
