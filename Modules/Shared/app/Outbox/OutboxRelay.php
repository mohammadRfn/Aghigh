<?php

declare(strict_types=1);

namespace Modules\Shared\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Shared\Events\OutboxMessage;
use stdClass;
use Throwable;

/**
 * Outbox relay, read side. Claims due rows with FOR UPDATE SKIP LOCKED in a SHORT transaction and
 * leases them (available_at = now + lease, attempts + 1), then publishes each row OUTSIDE any
 * transaction and marks it processed. A crash between publish and mark re-publishes after the lease:
 * delivery is at-least-once, so every consumer must be idempotent (event id = outbox row id).
 */
final class OutboxRelay
{
    public function __construct(private readonly OutboxEventMap $map, private readonly Dispatcher $events) {}

    /** Relay batches until a batch comes back short or the time budget is spent. Returns rows published. */
    public function relay(int $batchSize, float $budgetSeconds): int
    {
        $deadline = microtime(true) + max(0.1, $budgetSeconds);
        $published = 0;
        do {
            [$claimed, $done] = $this->relayBatch($batchSize);
            $published += $done;
        } while ($claimed >= $batchSize && microtime(true) < $deadline);

        return $published;
    }

    /** @return array{0: int, 1: int} rows claimed, rows published */
    public function relayBatch(int $limit): array
    {
        $rows = $this->claim(max(1, min($limit, 1000)));
        $published = 0;
        foreach ($rows as $row) {
            $published += $this->publish($row) ? 1 : 0;
        }

        return [count($rows), $published];
    }

    /** @return list<stdClass> */
    private function claim(int $limit): array
    {
        $lease = max(5, (int) config('shared.outbox.lease_seconds', 60));

        return DB::transaction(function () use ($limit, $lease): array {
            $now = CarbonImmutable::now();
            $rows = DB::table('outbox')
                ->whereNull('processed_at')
                ->where('available_at', '<=', $now)
                ->orderBy('available_at')->orderBy('id')
                ->limit($limit)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)
                ->get(['id', 'aggregate_type', 'aggregate_id', 'type', 'payload', 'occurred_at', 'attempts']);
            if ($rows->isEmpty()) {
                return [];
            }
            DB::table('outbox')->whereIn('id', $rows->pluck('id')->all())->whereNull('processed_at')->update([
                'available_at' => $now->addSeconds($lease),
                'attempts' => DB::raw('attempts + 1'),
            ]);

            /** @var list<stdClass> $claimed */
            $claimed = $rows->values()->all();

            return $claimed;
        });
    }

    private function publish(stdClass $row): bool
    {
        $attempt = (int) $row->attempts + 1;
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row->payload, true, 32, JSON_THROW_ON_ERROR);
            $message = new OutboxMessage(
                (string) $row->id, (string) $row->aggregate_type, (string) $row->aggregate_id, (string) $row->type,
                $payload, CarbonImmutable::parse((string) $row->occurred_at), $attempt,
            );
            $this->events->dispatch($this->map->eventFor($message));
            DB::table('outbox')->where('id', $row->id)->whereNull('processed_at')
                ->update(['processed_at' => CarbonImmutable::now(), 'last_error' => null]);

            return true;
        } catch (Throwable $error) {
            $delay = min(3600, 5 * (2 ** min(10, $attempt - 1)));
            // Class name only: exception messages may carry data that must not be persisted or logged.
            DB::table('outbox')->where('id', $row->id)->whereNull('processed_at')
                ->update(['available_at' => CarbonImmutable::now()->addSeconds($delay), 'last_error' => $error::class]);
            Log::warning('outbox_relay_publish_failed', ['outbox_id' => $row->id, 'type' => $row->type, 'attempt' => $attempt, 'exception' => $error::class]);

            return false;
        }
    }
}
