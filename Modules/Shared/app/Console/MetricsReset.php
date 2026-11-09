<?php

declare(strict_types=1);

namespace Modules\Shared\Console;

use Illuminate\Console\Command;
use Modules\Shared\Metrics\Collectors\QueueGauges;
use Modules\Shared\Metrics\Collectors\ReverbGauges;
use Modules\Shared\Metrics\Failures;
use Modules\Shared\Metrics\MetricsStore;
use Modules\Shared\Metrics\ScrapeCache;
use Throwable;

/** Wipes the metrics db (Task 10: make loadtest-reset). Refused in production; never touches state/queue/cache dbs. */
final class MetricsReset extends Command
{
    protected $signature = 'metrics:reset';

    protected $description = 'Delete every metric series and scrape cache entry from the metrics Redis db (not in production)';

    public function handle(MetricsStore $store, ScrapeCache $cache): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('metrics:reset is refused in production.');

            return self::FAILURE;
        }

        try {
            $store->wipe();
            $cache->forget(QueueGauges::CACHE_KEY);
            $cache->forget(ReverbGauges::CACHE_KEY);
            Failures::reset();
        } catch (Throwable $error) {
            $this->error('metrics:reset failed: '.$error::class.' '.$error->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Metrics wiped (driver %s).', $store->driver()));

        return self::SUCCESS;
    }
}
