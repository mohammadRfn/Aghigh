<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use InvalidArgumentException;
use Modules\Catalog\Console\ReconcileCache;
use Modules\Catalog\Console\SeedBulk;
use Modules\Catalog\Contracts\ProductPricing;
use Modules\Catalog\Services\DatabasePricing;
use Nwidart\Modules\Support\ModuleServiceProvider;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Catalog';

    protected string $nameLower = 'catalog';

    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    protected array $commands = [SeedBulk::class, ReconcileCache::class];

    public function register(): void
    {
        parent::register();
        $this->app->bind(ProductPricing::class, DatabasePricing::class);
    }

    public function boot(): void
    {
        parent::boot();
        $default = (int) config('catalog.per_page');
        $max = (int) config('catalog.per_page_max');
        if ($default < 1 || $default > $max || $max > 50) {
            throw new InvalidArgumentException('Catalog page defaults require 1 <= default <= max <= 50.');
        }
        if (! preg_match('/^[A-Z]{3}$/D', (string) config('catalog.currency'))) {
            throw new InvalidArgumentException('catalog.currency must be a three-letter uppercase ISO currency code.');
        }
        if ((int) config('catalog.cache_fresh_seconds') < 1 || (int) config('catalog.cache_stale_seconds') <= (int) config('catalog.cache_fresh_seconds')) {
            throw new InvalidArgumentException('Catalog stale TTL must exceed its positive fresh TTL.');
        }
        foreach (['cache_lock_seconds', 'cache_lock_wait_seconds', 'pricing_max_ids', 'images_max', 'category_max_depth', 'price_max_minor'] as $key) {
            if ((int) config('catalog.'.$key) < 1) {
                throw new InvalidArgumentException('Catalog positive configuration required: '.$key);
            }
        }
        if ((int) config('catalog.price_max_minor') > 9007199254740991) {
            throw new InvalidArgumentException('Catalog money must fit the safe JSON integer range.');
        }
        // Task 5 must retain this integration key or explicitly revise this guard.
        if ((int) config('catalog.pricing_max_ids') < (int) config('ordering.max_items', 100)) {
            throw new InvalidArgumentException('CATALOG_PRICING_MAX_IDS must cover the ordering.max_items limit (pre-Task-5 floor: 100).');
        }
    }
}
