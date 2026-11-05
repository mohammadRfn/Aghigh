<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Ordering\Services\ReservationExpiry;
use Modules\Shared\Queue\Backoff;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Modules\Shared\Queue\Queues;
use Throwable;

/** Scheduled every minute. ShouldBeUnique + an overlap lock: one sweep at a time, the next tick resumes. */
final class ExpireReservations implements ShouldBeUnique, ShouldQueue
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
        return 'ordering-expire-reservations';
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
        return [(new WithoutOverlappingOnState('ordering:expiry-sweep'))->shared()->dontRelease()->expireAfter($this->timeout + 30)];
    }

    public function handle(ReservationExpiry $expiry): void
    {
        $expired = $expiry->sweep((int) config('ordering.expiry.batch', 500), (int) config('ordering.expiry.max_batches', 20));
        if ($expired > 0) {
            Log::info('order_reservations_expired', ['orders' => $expired]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('order_expiry_sweep_failed', ['exception' => $exception === null ? null : $exception::class]);
    }
}
