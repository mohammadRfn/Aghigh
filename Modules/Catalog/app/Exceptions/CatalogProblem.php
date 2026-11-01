<?php

declare(strict_types=1);

namespace Modules\Catalog\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use RuntimeException;

final class CatalogProblem extends RuntimeException implements ProblemException
{
    public function __construct(string $message, private readonly int $status = 422, private readonly string $type = 'catalog-validation')
    {
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
        return [];
    }
}
