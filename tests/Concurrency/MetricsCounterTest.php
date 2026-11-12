<?php

declare(strict_types=1);

use Modules\Shared\Metrics\MetricsStore;
use Modules\Shared\Metrics\Names;
use Tests\Support\Race;

/*
 | Task 9: the redis metrics driver is shared by every Octane worker and Horizon process. Six REAL processes
 | increment one counter 200 times each, concurrently; the exposition must show exactly 1200 (promphp uses
 | HINCRBYFLOAT inside Lua: no lost updates). Needs make test-int (METRICS_DRIVER=redis, REDIS_METRICS_DB=13).
 */

it('counts exactly across parallel processes on the real metrics db', function (): void {
    if (config('shared.metrics.driver') !== 'redis' || ! (bool) config('shared.metrics.enabled')) {
        $this->markTestSkipped('Needs METRICS_DRIVER=redis (make test-int).');
    }

    $store = app(MetricsStore::class);
    $store->wipe();

    $workers = 6;
    $perWorker = 200;
    $results = Race::run(base_path('tests/Concurrency/metrics-worker.php'), array_fill(0, $workers, [$perWorker]));

    expect($results)->toHaveCount($workers);
    foreach ($results as $result) {
        expect($result['ok'] ?? false)->toBeTrue()
            ->and($result['failures'] ?? null)->toBe(0);
    }

    $text = (string) $store->render();
    preg_match('/^'.preg_quote(Names::STATE_STORE_ERRORS, '/').'\{component="race_test"\} (\d+(?:\.\d+)?)$/m', $text, $match);

    expect($match)->not->toBeEmpty()
        ->and((float) $match[1])->toBe((float) ($workers * $perWorker));
});
