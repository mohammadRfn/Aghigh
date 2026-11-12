<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Tiny JSON cache for scrape-time collectors on the `metrics` Redis db (never `state`): several Prometheus
 * instances or a curious human cannot stampede Horizon or Reverb. A broken Redis means "compute every time".
 * With a null connection (memory/null drivers, tests) it is a pass-through.
 */
final class ScrapeCache
{
    private const PREFIX = 'metrics:scrape:';

    public function __construct(private readonly RedisFactory $redis, private readonly ?string $connection) {}

    /**
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public function remember(string $key, int $ttlSeconds, Closure $compute): array
    {
        if ($this->connection === null || $ttlSeconds <= 0) {
            return $compute();
        }
        try {
            $raw = $this->redis->connection($this->connection)->command('get', [self::PREFIX.$key]);
            if (is_string($raw)) {
                $cached = json_decode($raw, true);
                if (is_array($cached)) {
                    /** @var array<string, mixed> $cached */
                    return $cached;
                }
            }
        } catch (Throwable) {
            // fall through: compute without the cache
        }

        $value = $compute();

        try {
            $this->redis->connection($this->connection)->command('set', [self::PREFIX.$key, json_encode($value, JSON_THROW_ON_ERROR), 'EX', $ttlSeconds]);
        } catch (Throwable) {
            // best effort
        }

        return $value;
    }

    public function forget(string $key): void
    {
        if ($this->connection === null) {
            return;
        }
        try {
            $this->redis->connection($this->connection)->command('del', [self::PREFIX.$key]);
        } catch (Throwable) {
            // best effort
        }
    }
}
