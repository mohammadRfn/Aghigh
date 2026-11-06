<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use Modules\Shared\Contracts\ProblemHeaders;
use RuntimeException;

/** Fail-closed answer when the limiter backend is down: 503 `rate-limit-unavailable`, retryable. */
final class LimiterUnavailable extends RuntimeException implements ProblemException, ProblemHeaders
{
    public function __construct(private readonly string $limiter)
    {
        parent::__construct('The service is temporarily unable to accept this request. Retry shortly.');
    }

    public function problemStatus(): int
    {
        return 503;
    }

    public function problemType(): string
    {
        return 'rate-limit-unavailable';
    }

    public function problemExtensions(): array
    {
        return ['limiter' => $this->limiter];
    }

    public function problemHeaders(): array
    {
        return ['Retry-After' => '2'];
    }
}
