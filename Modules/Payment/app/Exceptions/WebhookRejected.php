<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use RuntimeException;

/**
 * Every webhook verification failure renders the SAME 401 problem+json (no hint about which check
 * failed). The reason is only for the security log.
 */
final class WebhookRejected extends RuntimeException implements ProblemException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct('Authentication is required.');
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function problemStatus(): int
    {
        return 401;
    }

    public function problemType(): string
    {
        return 'unauthenticated';
    }

    public function problemExtensions(): array
    {
        return [];
    }
}
