<?php

declare(strict_types=1);

namespace Modules\Shared\Providers;

use Modules\Shared\Console\QueueDrainTime;
use Modules\Shared\Console\QueueMetricsCommand;
use Modules\Shared\Outbox\OutboxEventMap;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SharedServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Shared';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'shared';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [QueueMetricsCommand::class, QueueDrainTime::class];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
        RateLimitServiceProvider::class, // Task 7: named limiters, throttle.sliding + concurrency.cap
    ];

    public function register(): void
    {
        parent::register();
        // Filled once at boot by the feature modules, read-only afterwards (safe under Octane).
        $this->app->singleton(OutboxEventMap::class);
    }
}
