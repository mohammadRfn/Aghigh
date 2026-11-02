<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

/** A ProblemException that also needs response headers (for example Retry-After). */
interface ProblemHeaders
{
    /** @return array<string, string> */
    public function problemHeaders(): array;
}
