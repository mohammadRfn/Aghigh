<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/** Lookup for modules that must contact a user they only know by id (e.g. order notifications). */
interface UserDirectory
{
    /** The address to notify, or null when the user no longer exists. */
    public function emailOf(string $userId): ?string;
}
