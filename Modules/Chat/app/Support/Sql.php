<?php

declare(strict_types=1);

namespace Modules\Chat\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class Sql
{
    /** GREATEST() on Postgres, scalar MAX() on SQLite (Feature suite). */
    public static function greatest(string $a, string $b): string
    {
        return DB::getDriverName() === 'sqlite' ? "MAX({$a}, {$b})" : "GREATEST({$a}, {$b})";
    }

    /** Statement with RETURNING, always on the write connection. */
    public static function returning(string $sql, array $bindings): ?object
    {
        $row = DB::connection()->selectOne($sql, $bindings, false);

        return is_object($row) ? $row : null;
    }

    public static function isUniqueViolation(\Throwable $error): bool
    {
        if ($error instanceof UniqueConstraintViolationException) {
            return true;
        }

        return $error instanceof QueryException && (string) ($error->errorInfo[0] ?? $error->getCode()) === '23505';
    }
}
