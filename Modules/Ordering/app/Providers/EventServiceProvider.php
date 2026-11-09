<?php

declare(strict_types=1);

namespace Modules\Ordering\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Ordering\Events\OrderCancelled;
use Modules\Ordering\Events\OrderConfirmed;
use Modules\Ordering\Events\OrderExpired;
use Modules\Ordering\Events\OrderFulfilled;
use Modules\Ordering\Events\OrderPaid;
use Modules\Ordering\Events\OrderReserved;
use Modules\Ordering\Listeners\RecordOrderMetrics;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // Task 9: outcome counter (metrics never throw, so these listeners cannot break the relay).
        OrderReserved::class => [RecordOrderMetrics::class],
        OrderPaid::class => [RecordOrderMetrics::class],
        OrderConfirmed::class => [RecordOrderMetrics::class],
        OrderFulfilled::class => [RecordOrderMetrics::class],
        OrderCancelled::class => [RecordOrderMetrics::class],
        OrderExpired::class => [RecordOrderMetrics::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
