<?php

declare(strict_types=1);

namespace Modules\Shared\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Transactional outbox, write side: the event row commits or rolls back together with the state
 * change that caused it. A relay (later task) publishes unprocessed rows at-least-once.
 */
final class Outbox
{
    /** @param array<string, mixed> $payload ids and timestamps only */
    public function record(string $aggregateType, string $aggregateId, string $type, array $payload): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Outbox::record() must run inside the transaction that changes the aggregate.');
        }

        $id = (string) Str::uuid7();
        $now = CarbonImmutable::now();
        DB::table('outbox')->insert([
            'id' => $id,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        return $id;
    }
}
