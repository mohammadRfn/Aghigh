<?php

declare(strict_types=1);

namespace Modules\Ordering\Events;

use Carbon\CarbonImmutable;
use Modules\Shared\Contracts\OutboxDomainEvent;
use Modules\Shared\Events\OutboxMessage;

/**
 * Base of the order lifecycle events relayed from the outbox. Payload is ids only (order_id,
 * occurred_at): consumers load what they need and must be idempotent (at-least-once delivery).
 */
abstract readonly class OrderEvent implements OutboxDomainEvent
{
    final public function __construct(
        public string $orderId,
        public CarbonImmutable $occurredAt,
        public string $eventId,
        public int $attempt = 1,
    ) {}

    public static function fromOutbox(OutboxMessage $message): static
    {
        $occurredAt = isset($message->payload['occurred_at']) ? CarbonImmutable::parse((string) $message->payload['occurred_at']) : $message->occurredAt;

        return new static((string) ($message->payload['order_id'] ?? $message->aggregateId), $occurredAt, $message->id, $message->attempt);
    }
}
