<?php

declare(strict_types=1);

namespace Modules\Inventory\Providers;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Inventory\Console\SeedStock;
use Modules\Inventory\Console\SetStock;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Inventory\Contracts\StockStrategy;
use Modules\Inventory\Services\StockReservationService;
use Modules\Inventory\Strategies\AtomicStockStrategy;
use Modules\Inventory\Strategies\LockingStockStrategy;
use Modules\Inventory\Strategies\NaiveStockStrategy;
use Modules\Shared\Support\UnsafeEnvironment;
use Nwidart\Modules\Support\ModuleServiceProvider;

final class InventoryServiceProvider extends ModuleServiceProvider
{
    public const STRATEGIES = ['naive', 'atomic', 'locking'];

    protected string $name = 'Inventory';

    protected string $nameLower = 'inventory';

    /** @var string[] */
    protected array $commands = [SetStock::class, SeedStock::class];

    /** @var string[] */
    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    public function register(): void
    {
        parent::register();
        // Resolved per use (not a singleton) so the strategy follows config at resolution time
        // (the Concurrency workers switch it per process).
        $this->app->bind(StockStrategy::class, fn (Application $app): StockStrategy => self::strategy($app));
        $this->app->bind(StockReservations::class, StockReservationService::class);
    }

    public function boot(): void
    {
        parent::boot();
        self::strategy($this->app); // fail fast on a forbidden or unknown STOCK_STRATEGY
        $delay = (int) config('inventory.naive_delay_ms');
        if ($delay < 0 || $delay > 60_000) {
            throw new InvalidArgumentException('STOCK_NAIVE_DELAY_MS must be between 0 and 60000.');
        }
        if ($delay > 0 && ! UnsafeEnvironment::allows((string) $this->app->environment())) {
            throw new InvalidArgumentException('STOCK_NAIVE_DELAY_MS is allowed only in local, testing or loadtest.');
        }
        if ((int) config('inventory.max_stock') < 1) {
            throw new InvalidArgumentException('INVENTORY_MAX_STOCK must be positive.');
        }
    }

    private static function strategy(Application $app): StockStrategy
    {
        $name = (string) config('inventory.strategy');
        $environment = (string) $app->environment();

        return match ($name) {
            'naive' => UnsafeEnvironment::allows($environment)
                ? $app->make(NaiveStockStrategy::class)
                : throw new InvalidArgumentException('STOCK_STRATEGY=naive is allowed only in local, testing or loadtest (it loses updates on purpose).'),
            'atomic' => $app->make(AtomicStockStrategy::class),
            'locking' => $app->make(LockingStockStrategy::class),
            default => throw new InvalidArgumentException('STOCK_STRATEGY must be one of: '.implode(', ', self::STRATEGIES).'.'),
        };
    }
}
