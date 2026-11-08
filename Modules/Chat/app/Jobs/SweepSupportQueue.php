<?php

declare(strict_types=1);

namespace Modules\Chat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Chat\Services\SupportQueue;
use Modules\Chat\Support\ChatLog;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Throwable;

/**
 * Scheduled repair of chat:support-queue (routes/console.php: onOneServer + withoutOverlapping; the job itself
 * holds a mutex on the `state` store). Idempotent, so tries 1: the next minute's run is the retry.
 */
final class SweepSupportQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60; // below the default supervisor timeout (100 s) and retry_after

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlappingOnState('chat-support-queue-sweep'))->expireAfter(120)->dontRelease()];
    }

    public function handle(SupportQueue $queue): void
    {
        $result = $queue->sweep();
        if ($result['added'] > 0 || $result['removed'] > 0) {
            ChatLog::warning('chat_queue_repaired', null, ['added' => $result['added'], 'removed' => $result['removed']]);
        }
    }

    public function failed(?Throwable $error = null): void
    {
        ChatLog::warning('chat_queue_sweep_failed', $error);
    }
}
