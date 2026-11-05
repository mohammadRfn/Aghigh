<?php

declare(strict_types=1);

namespace Modules\Shared\Jobs;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Shared\Outbox\OutboxRelay;
use Modules\Shared\Queue\Backoff;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Modules\Shared\Queue\Queues;
use Throwable;

/**
 * Scheduled every minute (routes/console.php). ShouldBeUnique: at most one relay job queued or
 * running; SKIP LOCKED + leases would still make two relays safe, only wasteful.
 */
final class RelayOutbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(Queues::DEFAULT);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'shared-outbox-relay';
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(WithoutOverlappingOnState::store());
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return Backoff::exponential(5, $this->tries - 1, 60);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlappingOnState('shared:outbox-relay'))->shared()->dontRelease()->expireAfter($this->timeout + 30)];
    }

    public function handle(OutboxRelay $relay): void
    {
        $relay->relay((int) config('shared.outbox.batch', 200), (float) config('shared.outbox.budget_seconds', 50));
    }

    public function failed(?Throwable $exception): void
    {
        // Nothing to compensate: unprocessed rows stay due and the next tick relays them.
        Log::warning('outbox_relay_job_failed', ['exception' => $exception === null ? null : $exception::class]);
    }
}
