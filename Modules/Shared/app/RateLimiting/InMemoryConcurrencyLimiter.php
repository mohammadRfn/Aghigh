<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Closure;
use Modules\Shared\Contracts\ConcurrencyLimiter;

/** In-process twin of RedisConcurrencyLimiter for the Feature suite (same expiry semantics). */
final class InMemoryConcurrencyLimiter implements ConcurrencyLimiter
{
    /** @var array<string, array<string, int>> key -> member -> expiry ms */
    private array $slots = [];

    private ?string $failure = null;

    /** @param Closure(): int|null $clock */
    public function __construct(private ?Closure $clock = null) {}

    public function acquire(string $key, string $member, int $max, int $ttlMs, ?int $nowMs = null): bool
    {
        if ($this->failure !== null) {
            throw new LimiterBackendUnavailable($this->failure);
        }
        $now = $nowMs ?? $this->now();
        $this->slots[$key] = array_filter($this->slots[$key] ?? [], static fn (int $expiry): bool => $expiry > $now);
        if (count($this->slots[$key]) >= max(1, $max)) {
            return false;
        }
        $this->slots[$key][$member] = $now + max(1, $ttlMs);

        return true;
    }

    public function release(string $key, string $member): void
    {
        unset($this->slots[$key][$member]);
    }

    public function inFlight(string $key, ?int $nowMs = null): int
    {
        $now = $nowMs ?? $this->now();

        return count(array_filter($this->slots[$key] ?? [], static fn (int $expiry): bool => $expiry > $now));
    }

    public function reset(): void
    {
        $this->slots = [];
        $this->failure = null;
    }

    public function failWith(?string $message): void
    {
        $this->failure = $message;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : (int) floor(microtime(true) * 1000);
    }
}
