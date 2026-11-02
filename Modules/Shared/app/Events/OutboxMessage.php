<?php

declare(strict_types=1);

namespace Modules\Shared\Events;

use Carbon\CarbonImmutable;

/** One outbox row as seen by the relay and by event factories. Ids only in the payload. */
final readonly class OutboxMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $aggregateType,
        public string $aggregateId,
        public string $type,
        public array $payload,
        public CarbonImmutable $occurredAt,
        public int $attempt = 1,
    ) {}
}
