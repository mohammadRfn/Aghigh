<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Logs queries slower than LOG_SLOW_QUERY_MS to the slow_query channel.
 * Bindings are never logged (they may hold personal data), only their count.
 */
final class SlowQueryListener
{
    public static function register(): void
    {
        $threshold = (float) config('logging.slow_query_ms', 200);

        if ($threshold <= 0) {
            return;
        }

        DB::listen(static function (QueryExecuted $query) use ($threshold): void {
            if ($query->time < $threshold) {
                return;
            }

            Log::channel('slow_query')->warning('slow query', [
                'time_ms' => round($query->time, 2),
                'connection' => $query->connectionName,
                'sql' => $query->sql,
                'binding_count' => count($query->bindings),
            ]);
        });
    }
}
