<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use Modules\Shared\Contracts\ProblemHeaders;
use Modules\Shared\RateLimiting\RateLimitHeaders;
use Modules\Shared\RateLimiting\RateLimitResult;
use RuntimeException;

/**
 * 429 problem+json. Types: `too-many-requests` (HTTP limiter), `concurrency-limit-exceeded` (semaphore),
 * `orders-per-minute-exceeded` (business limit). Always carries Retry-After + RateLimit-* headers.
 */
final class RateLimitExceeded extends RuntimeException implements ProblemException, ProblemHeaders
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $type,
        private readonly array $headers,
        string $message = 'Too many requests. Try again later.',
    ) {
        parent::__construct($message);
    }

    public static function fromResult(RateLimitResult $result, string $type = 'too-many-requests'): self
    {
        return new self($type, RateLimitHeaders::fromResult($result));
    }

    public static function concurrency(int $max, int $retryAfterSeconds = 1): self
    {
        return new self('concurrency-limit-exceeded', [
            'Retry-After' => (string) max(1, $retryAfterSeconds),
            'RateLimit-Limit' => (string) $max,
            'RateLimit-Remaining' => '0',
            'RateLimit-Reset' => (string) max(1, $retryAfterSeconds),
        ], 'Too many concurrent requests. Wait for your previous request to finish.');
    }

    public function problemStatus(): int
    {
        return 429;
    }

    public function problemType(): string
    {
        return $this->type;
    }

    public function problemExtensions(): array
    {
        return ['retry_after' => (int) ($this->headers['Retry-After'] ?? 1)];
    }

    public function problemHeaders(): array
    {
        return $this->headers;
    }
}
