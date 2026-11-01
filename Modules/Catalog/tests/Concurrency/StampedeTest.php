<?php

declare(strict_types=1);

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Services\CatalogVersions;

it('computes a cold page once across real simultaneous HTTP worker processes', function (): void {
    Product::factory()->count(12)->create();
    app(CatalogVersions::class)->initialize();
    Cache::store('state')->forever(CatalogVersions::DIRTY, 0);
    app(CatalogVersions::class)->bump();
    $run = bin2hex(random_bytes(10));
    $counter = 'catalog:test:stampede:'.$run;
    Cache::store('state')->forever($counter, 0);
    $directory = sys_get_temp_dir().'/aghigh-catalog-'.$run;
    mkdir($directory, 0700);
    $workers = 8; // Test load, not a measured capacity claim.
    $script = __DIR__.'/catalog-worker.php';
    $running = Process::pool(function (Pool $pool) use ($workers, $script, $directory, $counter): void {
        for ($i = 0; $i < $workers; $i++) {
            $pool->as((string) $i)->path(base_path())->timeout(120)
                ->command(['php', $script, $directory, (string) $i, $counter]);
        }
    })->start();
    try {
        $deadline = microtime(true) + 60;
        while (count(glob($directory.'/ready-*') ?: []) < $workers && microtime(true) < $deadline) {
            usleep(20000);
        }
        $ready = count(glob($directory.'/ready-*') ?: []);
        touch($directory.'/go');
        $results = $running->wait()->collect();
        expect($ready)->toBe($workers);
        $bodies = [];
        foreach ($results as $result) {
            expect($result->successful())->toBeTrue($result->errorOutput());
            $lines = preg_split('/\R/', trim($result->output())) ?: [];
            $response = json_decode((string) end($lines), true, 16, JSON_THROW_ON_ERROR);
            expect($response['status'])->toBe(200);
            $bodies[] = $response['ids'];
        }
        expect(count($bodies))->toBe($workers);
        foreach ($bodies as $body) {
            expect($body)->toBe($bodies[0]);
        }
        // Incremented only when the actual products SELECT executes, on STATE.
        expect((int) Cache::store('state')->get($counter))->toBe(1);
    } finally {
        if (! file_exists($directory.'/go')) {
            touch($directory.'/go');
            $running->wait();
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
        Cache::store('state')->forget($counter);
    }
});
