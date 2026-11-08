<?php

declare(strict_types=1);

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\UnreadCounters;

final class RebuildUnread extends Command
{
    protected $signature = 'chat:rebuild-unread {--chunk=500 : Conversations per round trip}';

    protected $description = 'Recompute the Redis unread counters of open conversations from conversation_reads (the truth)';

    public function handle(UnreadCounters $unread): int
    {
        $written = $unread->rebuild(max(1, (int) $this->option('chunk')));
        $this->info("unread counters written: {$written}");

        return self::SUCCESS;
    }
}
