<?php

declare(strict_types=1);

namespace Modules\Chat\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Public Chat event (Task 9 metrics subscribe to it; Chat needs no change). Dispatched only after the
 * transaction that persisted the change commits; carries ids and scalars only (never message bodies).
 */
final class ConversationClaimed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $agentId,
    ) {}
}
