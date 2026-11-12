<?php

declare(strict_types=1);

/*
 | Race worker for MetricsCounterTest: one real PHP process per worker, all released together by Race::run(),
 | each incrementing the SAME counter N times on the real metrics Redis db (make test-int: REDIS_METRICS_DB=13).
 */

use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Metrics\Failures;
use Modules\Shared\Metrics\Names;
use Tests\Support\Race;

require dirname(__DIR__, 2).'/vendor/autoload.php';

Race::worker($argv, function (array $arguments): array {
    $increments = (int) ($arguments[0] ?? 100);
    $metrics = app(Metrics::class);

    for ($i = 0; $i < $increments; $i++) {
        $metrics->counter(Names::STATE_STORE_ERRORS, ['component' => 'race_test']);
    }

    return ['increments' => $increments, 'failures' => Failures::total(), 'pid' => getmypid()];
});
