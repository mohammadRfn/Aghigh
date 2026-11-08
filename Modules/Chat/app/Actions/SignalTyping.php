<?php

declare(strict_types=1);

namespace Modules\Chat\Actions;

use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Events\TypingStarted;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\State\ChatState;
use Modules\Chat\Support\ChatLog;
use Modules\Identity\Contracts\CurrentUser;
use Throwable;

/**
 * POST /conversations/{id}/typing: a 5 s key plus a broadcast throttled to one per 3 s (SET NX EX).
 * Never persisted; fails open (a Redis outage only drops the indicator). Returns whether a broadcast was queued.
 */
final class SignalTyping
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly ChatState $state,
    ) {}

    public function __invoke(CurrentUser $actor, string $conversationId): bool
    {
        $conversation = $this->policy->find($actor, $conversationId) ?? throw ChatProblem::notFound();
        if ((string) $conversation->status === ConversationStatus::Closed->value) {
            throw ChatProblem::closed();
        }
        if (! $this->policy->canPost($actor, $conversation)) {
            throw ChatProblem::forbidden('You cannot type in this conversation.');
        }
        $id = (string) $conversation->id;
        $ttl = (int) config('chat.typing_ttl_seconds', 5);
        try {
            $this->state->typingMark($id, $actor->id, $ttl);
            if (! $this->state->typingBroadcastSlot($id, $actor->id, (int) config('chat.typing_broadcast_every_seconds', 3))) {
                return false;
            }
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'typing']);

            return false;
        }
        event(new TypingStarted($id, $actor->id, $actor->role, $ttl));

        return true;
    }
}
