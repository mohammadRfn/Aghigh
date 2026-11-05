<?php

declare(strict_types=1);

namespace Modules\Shared\Outbox;

use InvalidArgumentException;
use Modules\Shared\Contracts\OutboxDomainEvent;
use Modules\Shared\Events\OutboxMessage;

/**
 * Outbox row type => domain event class. Feature modules register their own types at boot
 * (Shared never imports them). Unknown types are published as the generic OutboxMessage.
 */
final class OutboxEventMap
{
    /** @var array<string, class-string<OutboxDomainEvent>> */
    private array $types = [];

    /** @param array<string, class-string<OutboxDomainEvent>> $types */
    public function register(array $types): void
    {
        foreach ($types as $type => $class) {
            if (! is_subclass_of($class, OutboxDomainEvent::class)) {
                throw new InvalidArgumentException("Outbox type {$type} must map to an OutboxDomainEvent.");
            }
            $this->types[$type] = $class;
        }
    }

    public function eventFor(OutboxMessage $message): object
    {
        $class = $this->types[$message->type] ?? null;

        return $class === null ? $message : $class::fromOutbox($message);
    }

    /** @return array<string, class-string<OutboxDomainEvent>> */
    public function all(): array
    {
        return $this->types;
    }
}
