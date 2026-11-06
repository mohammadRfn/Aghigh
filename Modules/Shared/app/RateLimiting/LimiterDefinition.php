<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use InvalidArgumentException;

/** One named limiter from config/rate-limits.php, with the multiplier and the sliding hard cap already applied. */
final class LimiterDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly int $limit,
        public readonly int $windowSeconds,
        /** ip | user | user+ip | ip+email */
        public readonly string $by,
        /** laravel | sliding */
        public readonly string $driver,
        /** open | closed */
        public readonly string $failMode,
    ) {
        if (! in_array($this->driver, ['laravel', 'sliding'], true)) {
            throw new InvalidArgumentException("Limiter [{$name}]: driver must be laravel|sliding, got [{$this->driver}].");
        }
        if (! in_array($this->failMode, ['open', 'closed'], true)) {
            throw new InvalidArgumentException("Limiter [{$name}]: fail must be open|closed, got [{$this->failMode}].");
        }
    }

    public function failsOpen(): bool
    {
        return $this->failMode === 'open';
    }
}
