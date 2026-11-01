<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

final class BenchEnvironment
{
    public static function enabled(string $environment): bool
    {
        return in_array($environment, ['local', 'testing', 'loadtest'], true);
    }
}
