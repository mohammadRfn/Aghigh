<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Chat\Jobs\SweepSupportQueue;
use Modules\Ordering\Jobs\ExpireReservations;
use Modules\Shared\Jobs\RelayOutbox;
use Modules\Shared\Queue\Queues;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mutexes live on cache.schedule_store (SCHEDULE_CACHE_DRIVER=state: noeviction Redis), see config/cache.php.
Schedule::command('identity:prune-tokens')->daily()->onOneServer()->withoutOverlapping();

// The scheduler only PUSHES these (no database connection in the scheduler process). Both jobs are
// ShouldBeUnique with an overlap lock, which is what really keeps a slow run from overlapping the next.
Schedule::job(new ExpireReservations, Queues::DEFAULT)->everyMinute()
    ->name('ordering:expire-reservations')->onOneServer()->withoutOverlapping();
Schedule::job(new RelayOutbox, Queues::DEFAULT)->everyTenSeconds()
    ->name('shared:outbox-relay')->onOneServer()->withoutOverlapping();

// Task 8: repair chat:support-queue from the database (missed enqueue, crash between ZPOPMIN and the claim UPDATE,
// flushed Redis). The job also holds a mutex on the `state` store (WithoutOverlappingOnState).
Schedule::job(new SweepSupportQueue, Queues::DEFAULT)->everyMinute()
    ->name('chat:sweep-support-queue')->onOneServer()->withoutOverlapping();
