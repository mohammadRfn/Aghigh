<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

/**
 * Implemented by domain exceptions that want a specific problem+json response
 * (e.g. OutOfStock -> 409 "out-of-stock"). getMessage() is shown to the client,
 * so it must be safe to expose.
 */
interface ProblemException extends \Throwable
{
    public function problemStatus(): int;

    /** Short kebab-case slug; becomes {APP_URL}/problems/{slug}. */
    public function problemType(): string;

    /** @return array<string, mixed> extra members (reserved ones cannot be overridden) */
    public function problemExtensions(): array;
}