<?php

declare(strict_types=1);

namespace Modules\Ordering\Providers;

use Modules\Ordering\Console\VerifyInvariants;
use Modules\Ordering\Events\OrderCancelled;
use Modules\Ordering\Events\OrderConfirmed;
use Modules\Ordering\Events\OrderExpired;
use Modules\Ordering\Events\OrderFulfilled;
use Modules\Ordering\Events\OrderPaid;
use Modules\Ordering\Events\OrderReserved;
use Modules\Shared\Outbox\OutboxEventMap;
use Nwidart\Modules\Support\ModuleServiceProvider;

class OrderingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Ordering';

    protected string $nameLower = 'ordering';

    /** @var string[] */
    protected array $commands = [VerifyInvariants::class];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        // Outbox type => event published by the relay (at-least-once; payload ids only).
        $this->app->make(OutboxEventMap::class)->register([
            'OrderReserved' => OrderReserved::class,
            'OrderPaid' => OrderPaid::class,
            'OrderConfirmed' => OrderConfirmed::class,
            'OrderFulfilled' => OrderFulfilled::class,
            'OrderCancelled' => OrderCancelled::class,
            'OrderExpired' => OrderExpired::class,
        ]);
    }
}
