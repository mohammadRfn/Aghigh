<?php

declare(strict_types=1);

namespace Modules\Ordering\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use Modules\Shared\Contracts\ProblemHeaders;
use RuntimeException;

final class OrderingProblem extends RuntimeException implements ProblemException, ProblemHeaders
{
    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly string $type = 'order-validation',
        private readonly array $extensions = [],
        private readonly array $headers = [],
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

    public function problemHeaders(): array
    {
        return $this->headers;
    }
}
