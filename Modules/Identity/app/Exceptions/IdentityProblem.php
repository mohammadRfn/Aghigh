<?php

declare(strict_types=1);

namespace Modules\Identity\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use RuntimeException;

final class IdentityProblem extends RuntimeException implements ProblemException
{
    public function __construct(private readonly string $slug, private readonly int $status, string $detail)
    {
        parent::__construct($detail);
    }

    public function problemStatus(): int
    {
        return $this->status;
    }

    public function problemType(): string
    {
        return $this->slug;
    }

    /** @return array<string, mixed> */
    public function problemExtensions(): array
    {
        return [];
    }
}
