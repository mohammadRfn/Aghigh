<?php

declare(strict_types=1);

namespace Modules\Shared\Support;

/** Environments where deliberately unsafe test aids (naive stock strategy, fixtures) may run. */
final class UnsafeEnvironment
{
    public const ENVIRONMENTS = ['local', 'testing', 'loadtest'];

    public static function allows(string $environment): bool
    {
        return in_array($environment, self::ENVIRONMENTS, true);
    }
}
