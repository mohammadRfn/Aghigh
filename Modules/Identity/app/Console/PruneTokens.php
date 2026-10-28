<?php

declare(strict_types=1);

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PruneTokens extends Command
{
    protected $signature = 'identity:prune-tokens {--batch=500 : Maximum rows per table, capped at 1000}';

    protected $description = 'Prune expired identity data in bounded batches';

    public function handle(): int
    {
        $batch = max(1, min(1000, (int) $this->option('batch')));
        $this->prune('identity_access_tokens', DB::table('identity_access_tokens')->where('expires_at', '<=', now()), $batch);
        $this->prune('password_reset_tokens', DB::table('password_reset_tokens')->where(fn (Builder $query) => $query->whereNotNull('used_at')->orWhere('expires_at', '<=', now())), $batch);
        $this->prune('identity_mail_outbox', DB::table('identity_mail_outbox')->where('created_at', '<=', now()->subDays(30)), $batch);
        // Keep used-parent tombstones until their family's absolute expiry, so delayed reuse is detectable.
        $this->prune('refresh_tokens', DB::table('refresh_tokens')->where('expires_at', '<=', now())->where(function (Builder $query): void {
            $query->whereNull('used_at')->orWhereIn('family_id', DB::table('identity_token_families')->select('id')->where('expires_at', '<=', now()));
        }), $batch);
        // Do not cascade-delete unbounded child sets; only remove empty, expired families.
        $this->prune('identity_token_families', DB::table('identity_token_families')->where('expires_at', '<=', now())
            ->whereNotIn('id', DB::table('refresh_tokens')->select('family_id'))
            ->whereNotIn('id', DB::table('identity_access_tokens')->select('family_id')), $batch);
        $this->info('Bounded prune completed.');

        return self::SUCCESS;
    }

    private function prune(string $table, Builder $query, int $batch): void
    {
        $ids = $query->orderBy('id')->limit($batch)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table($table)->whereIn('id', $ids)->delete();
        }
    }
}
