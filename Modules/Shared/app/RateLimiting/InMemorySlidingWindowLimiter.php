<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Closure;
use Modules\Shared\Contracts\SlidingWindowLimiter;

/**
 * Same semantics as the Lua script, in process memory. Used by the Feature suite (`make test` has no Redis)
 * and bound only when RATE_LIMIT_SLIDING_DRIVER=memory. Counters are per PHP process by design: it is a
 * test double, never a production driver (the provider refuses it outside UnsafeEnvironment).
 */
final class InMemorySlidingWindowLimiter implements SlidingWindowLimiter
{
    /** @var array<string, list<int>> key -> attempt timestamps (ms, ascending) */
    private array $log = [];

    /** When set, every attempt throws: lets Feature tests exercise fail-open / fail-closed. */
    private ?string $failure = null;

    /** @param Closure(): int|null $clock epoch ms provider */
    public function __construct(private ?Closure $clock = null) {}

    public function attempt(string $key, int $limit, int $windowSeconds, ?int $nowMs = null): RateLimitResult
    {
        if ($this->failure !== null) {
            throw new LimiterBackendUnavailable($this->failure);
        }
        $limit = max(1, $limit);
        $windowMs = max(1, $windowSeconds) * 1000;
        $now = $nowMs ?? $this->now();

        $entries = array_values(array_filter($this->log[$key] ?? [], static fn (int $at): bool => $at > $now - $windowMs));
        $count = count($entries);
        $allowed = $count < $limit;
        $retry = 0;
        if ($allowed) {
            $entries[] = $now;
            sort($entries);
            $count++;
        } else {
            $retry = max(1, ($entries[0] + $windowMs) - $now);
        }
        $this->log[$key] = $entries;
        $reset = $entries === [] ? $now + $windowMs : $entries[0] + $windowMs;

        return new RateLimitResult($allowed, $limit, max(0, $limit - $count), $retry, $reset, $now);
    }

    public function clear(string $key): void
    {
        unset($this->log[$key]);
    }

    public function reset(): void
    {
        $this->log = [];
        $this->failure = null;
    }

    /** Simulate a Redis outage (null = healthy). */
    public function failWith(?string $message): void
    {
        $this->failure = $message;
    }

    /** @param Closure(): int|null $clock */
    public function useClock(?Closure $clock): void
    {
        $this->clock = $clock;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : (int) floor(microtime(true) * 1000);
    }
}
