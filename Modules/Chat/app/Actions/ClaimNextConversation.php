<?php

declare(strict_types=1);

namespace Modules\Chat\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\AssignmentReason;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Events\ConversationClaimed;
use Modules\Chat\Events\ConversationUpdated;
use Modules\Chat\Events\QueueUpdated;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Services\UnreadCounters;
use Modules\Chat\State\ChatState;
use Modules\Chat\Support\ChatLog;
use Modules\Chat\Support\ChatTime;
use Modules\Identity\Contracts\CurrentUser;
use Throwable;

/**
 * POST /support/queue/claim (support_agent). Atomic ZPOPMIN hands each queue entry to exactly one agent; the
 * conditional UPDATE ... WHERE status = 'waiting' is the real guard (ADR 0009).
 * - 0 rows (already claimed / closed): the entry is dropped, NOT re-added, and the next entry is tried.
 * - DB error: the entry is re-added with its original score, the error propagates.
 * - crash between ZPOPMIN and the UPDATE: the scheduled sweep re-adds the still-waiting conversation.
 */
final class ClaimNextConversation
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly ChatState $state,
        private readonly UnreadCounters $unread,
    ) {}

    public function __invoke(CurrentUser $actor): ?object
    {
        if (! $this->policy->isAgent($actor)) {
            throw ChatProblem::forbidden('Only support agents claim conversations.');
        }
        $cap = (int) config('chat.agent_max_open', 0);
        if ($cap > 0 && DB::table('conversations')->where('assigned_agent_id', $actor->id)->where('status', ConversationStatus::Claimed->value)->count() >= $cap) {
            throw new ChatProblem("You already hold {$cap} open conversations.", 409, 'agent-capacity');
        }

        for ($attempt = 0; $attempt < max(1, (int) config('chat.claim_attempts', 5)); $attempt++) {
            try {
                $popped = $this->state->queuePopMin();
            } catch (Throwable $error) {
                ChatLog::warning('chat_state_unavailable', $error, ['op' => 'queue_pop']);
                throw new ChatProblem('The support queue is temporarily unavailable.', 503, 'chat-queue-unavailable', [], ['Retry-After' => '1']);
            }
            if ($popped === null) {
                return null;
            }
            [$conversationId, $score] = $popped;

            try {
                $claimed = DB::transaction(fn (): ?object => $this->claim($conversationId, $actor));
            } catch (Throwable $error) {
                try {
                    $this->state->queueAdd($conversationId, $score);
                } catch (Throwable) {
                    // the sweep re-adds it from the database
                }
                throw $error;
            }

            if ($claimed !== null) {
                $this->unread->forget($conversationId, $actor->id);
                ChatLog::audit('chat_conversation_claimed', $conversationId, $actor->id);

                return $claimed;
            }
        }

        return null;
    }

    private function claim(string $conversationId, CurrentUser $actor): ?object
    {
        $now = ChatTime::db(ChatTime::now());
        $updated = DB::update(
            "UPDATE conversations SET status = 'claimed', assigned_agent_id = ?, updated_at = ? WHERE id = ? AND status = 'waiting'",
            [$actor->id, $now, $conversationId],
        );
        if ($updated === 0) {
            return null;
        }
        DB::table('assignments')->insert([
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId, 'agent_id' => $actor->id,
            'assigned_at' => $now, 'released_at' => null, 'reason' => AssignmentReason::Claimed->value,
        ]);
        event(new ConversationClaimed($conversationId, $actor->id));
        event(new QueueUpdated($conversationId, QueueUpdated::CLAIMED));
        event(new ConversationUpdated($conversationId));

        $row = DB::table('conversations')->where('id', $conversationId)->first();

        return is_object($row) ? $row : null;
    }
}
