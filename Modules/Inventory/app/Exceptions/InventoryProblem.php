<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use RuntimeException;

final class InventoryProblem extends RuntimeException implements ProblemException
{
    /** @param array<string, mixed> $extensions */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly string $type = 'inventory-validation',
        private readonly array $extensions = [],
    ) {
        parent::__construct($message);
    }

    public function problemStatus(): int
    {
        return $this->status;
    }

    public function problemType(): string
    {
        return $this->type;
    }

    public function problemExtensions(): array
    {
        return $this->extensions;
    }
}
