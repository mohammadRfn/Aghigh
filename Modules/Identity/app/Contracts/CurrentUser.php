<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/** The only public Identity surface. No Eloquent objects leave this module. */
final readonly class CurrentUser
{
    public function __construct(
        public string $id,
        public string $role,
        public bool $emailVerified,
    ) {}
}
