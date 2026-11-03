<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Modules\Shared\Contracts\ProblemException;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/**
 * Real races: one PHP process per worker, all booted and parked at a barrier, then released together.
 * Parent side: Race::run(). Worker side (inside a worker script): Race::worker().
 * Test load only, never a capacity claim.
 */
final class Race
{
    /**
     * @param  list<list<int|string>>  $arguments  extra command-line arguments, one list per worker
     * @param  array<string, string>  $environment  extra environment variables for every worker
     * @return list<array<string, mixed>> each worker's JSON result, in worker order
     */
    public static function run(string $script, array $arguments, int $timeoutSeconds = 120, array $environment = []): array
    {
        $workers = count($arguments);
        $directory = sys_get_temp_dir().'/aghigh-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        $running = Process::pool(function (Pool $pool) use ($arguments, $script, $directory, $timeoutSeconds, $environment): void {
            foreach (array_values($arguments) as $index => $extra) {
                $pool->as((string) $index)->path(base_path())->timeout($timeoutSeconds)->env($environment)
                    ->command(['php', $script, $directory, (string) $index, ...array_map(strval(...), $extra)]);
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

            if ($ready !== $workers) {
                throw new AssertionFailedError("Only {$ready} of {$workers} workers reached the barrier.");
            }

            $decoded = [];
            foreach ($results as $index => $result) {
                if (! $result->successful()) {
                    throw new AssertionFailedError("Worker {$index} crashed: ".$result->errorOutput().$result->output());
                }
                $lines = preg_split('/\R/', trim($result->output())) ?: [];
                $decoded[(int) $index] = (array) json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
            }
            ksort($decoded);

            return array_values($decoded);
        } finally {
            if (! file_exists($directory.'/go')) {
                touch($directory.'/go');
                $running->wait();
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * Worker side: boot the app, refuse anything but a PostgreSQL _test database, wait at the barrier,
     * run the job and print one JSON line. A thrown problem becomes {"ok":false,"error":<problem type>}.
     *
     * @param  list<string>  $argv
     * @param  Closure(list<string>): array<string, mixed>  $job  receives the extra arguments
     */
    public static function worker(array $argv, Closure $job): never
    {
        $directory = $argv[1];
        $worker = $argv[2];
        $arguments = array_slice($argv, 3);

        /** @var Application $app */
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = (string) config('database.default');
        if ($connection !== 'pgsql' || ! str_ends_with((string) config("database.connections.{$connection}.database"), '_test')) {
            fwrite(STDERR, 'Refusing to run a race worker outside a PostgreSQL _test database.');
            exit(2);
        }

        touch($directory.'/ready-'.$worker);
        $deadline = microtime(true) + 75;
        while (! file_exists($directory.'/go')) {
            if (microtime(true) > $deadline) {
                fwrite(STDERR, 'Race barrier timed out.');
                exit(3);
            }
            usleep(2000);
        }

        try {
            $result = ['ok' => true] + $job($arguments);
        } catch (Throwable $error) {
            $result = [
                'ok' => false,
                'error' => $error instanceof ProblemException ? $error->problemType() : $error::class,
                'message' => $error->getMessage(),
            ];
        }

        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
}
