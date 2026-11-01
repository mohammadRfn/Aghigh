<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Services\CatalogState;
use Modules\Catalog\Services\CatalogVersions;

final class ReconcileCache extends Command
{
    protected $signature = 'catalog:cache-reconcile {--confirm-writes-paused}';

    protected $description = 'Recover a dirty catalog cache after state failure; pause all admin and bulk writes first.';

    public function handle(CatalogVersions $versions): int
    {
        if (! $this->option('confirm-writes-paused')) {
            $this->error('Pause admin/bulk writes, wait for in-flight transactions, then pass --confirm-writes-paused.');

            return self::FAILURE;
        }
        CatalogState::lock('catalog:admin-write')->block((int) config('catalog.cache_lock_wait_seconds'), function () use ($versions): void {
            $versions->bump();
            if (! CatalogState::store()->forever(CatalogVersions::DIRTY, 0)) {
                throw new \RuntimeException('State recovery failed. Keep writes paused.');
            }
        });
        $this->info('Both catalog versions advanced; dirty marker cleared. Resume writes.');

        return self::SUCCESS;
    }
}
