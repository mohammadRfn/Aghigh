<?php

declare(strict_types=1);

namespace Modules\Chat\Listeners;

use Modules\Chat\Events\ConversationClaimed;
use Modules\Chat\Events\ConversationClosed;
use Modules\Chat\Events\MessagePersisted;
use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Metrics\LabelValue;
use Modules\Shared\Metrics\Names;

/**
 * Task 9: chat counters from the public Chat events (ids and roles only, after commit). Roles are a closed set
 * (customer, support_agent, ...), never user ids or conversation ids.
 */
final class RecordChatMetrics
{
    public function __construct(private readonly Metrics $metrics) {}

    public function messagePersisted(MessagePersisted $event): void
    {
        $this->metrics->counter(Names::CHAT_MESSAGES, ['sender_role' => LabelValue::slug($event->senderRole)]);
    }

    public function conversationClaimed(ConversationClaimed $event): void
    {
        $this->metrics->counter(Names::CHAT_CLAIMS);
    }

    public function conversationClosed(ConversationClosed $event): void
    {
        $this->metrics->counter(Names::CHAT_CLOSES, ['closed_by_role' => LabelValue::slug($event->closedByRole)]);
    }
}
