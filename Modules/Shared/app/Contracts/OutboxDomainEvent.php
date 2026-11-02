<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

use Modules\Shared\Events\OutboxMessage;

/** A domain event that can be rebuilt from a relayed outbox row. Delivery is at-least-once. */
interface OutboxDomainEvent
{
    public static function fromOutbox(OutboxMessage $message): static;
}
