<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

final class ParallelRefresh
{
    /**
     * Rotates the same refresh token from many real PHP processes released by a start barrier.
     *
     * @return list<array{status: int, error: string|null}>
     */
    public static function run(string $raw, int $workers): array
    {
        $directory = sys_get_temp_dir().'/aghigh-barrier-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $worker = __DIR__.'/rotate-worker.php';

        $running = Process::pool(function (Pool $pool) use ($workers, $worker, $raw, $directory): void {
            for ($i = 0; $i < $workers; $i++) {
                $pool->as((string) $i)->path(base_path())->timeout(150)
                    ->command(['php', $worker, $raw, $directory, (string) $i]);
            }
        })->start();

        // Wait until every worker has booted, then release them all at once.
        $deadline = microtime(true) + 90;
        while (count(glob($directory.'/ready-*') ?: []) < $workers && microtime(true) < $deadline) {
            usleep(20_000);
        }
        touch($directory.'/go');

        $outcomes = [];
        foreach ($running->wait()->collect() as $result) {
            $lines = preg_split('/\R/', trim($result->output())) ?: [];
            $decoded = json_decode((string) end($lines), true);
            $outcomes[] = is_array($decoded)
                ? ['status' => (int) ($decoded['status'] ?? 0), 'error' => isset($decoded['error']) ? (string) $decoded['error'] : null]
                : ['status' => 0, 'error' => 'worker-failed: '.trim($result->errorOutput())];
        }

        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);

        return $outcomes;
    }
}
