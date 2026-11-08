<?php

declare(strict_types=1);

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\SupportQueue;

final class RebuildQueue extends Command
{
    protected $signature = 'chat:rebuild-queue {--chunk=500 : Conversations per round trip}';

    protected $description = 'Re-add waiting conversations missing from chat:support-queue (ZADD NX, score = created_at) and drop entries that are no longer waiting';

    public function handle(SupportQueue $queue): int
    {
        $result = $queue->sweep(max(1, (int) $this->option('chunk')));
        $this->info("support queue: added {$result['added']}, removed {$result['removed']}, size {$result['size']}");

        return self::SUCCESS;
    }
}
