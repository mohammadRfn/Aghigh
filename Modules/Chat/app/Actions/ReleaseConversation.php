<?php

declare(strict_types=1);

namespace Modules\Chat\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\AssignmentReason;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Events\ConversationUpdated;
use Modules\Chat\Events\QueueUpdated;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Services\SupportQueue;
use Modules\Chat\Services\UnreadCounters;
use Modules\Chat\Support\ChatLog;
use Modules\Chat\Support\ChatTime;
use Modules\Identity\Contracts\CurrentUser;

/**
 * POST /conversations/{id}/release: the assigned agent returns a claimed conversation to the queue. The open
 * assignment is closed with reason=released; the queue entry gets its original score (created_at), so a released
 * conversation keeps its place at the front.
 */
final class ReleaseConversation
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly SupportQueue $queue,
        private readonly UnreadCounters $unread,
    ) {}

    public function __invoke(CurrentUser $actor, string $conversationId): object
    {
        $conversation = $this->policy->find($actor, $conversationId) ?? throw ChatProblem::notFound();
        if (! $this->policy->isAgent($actor)) {
            throw ChatProblem::forbidden('Only the assigned support agent releases a conversation.');
        }
        if ((string) $conversation->status === ConversationStatus::Closed->value) {
            throw ChatProblem::closed();
        }
        $id = (string) $conversation->id;

        $row = DB::transaction(function () use ($actor, $id): object {
            $now = ChatTime::db(ChatTime::now());
            $updated = DB::update(
                "UPDATE conversations SET status = 'waiting', assigned_agent_id = NULL, updated_at = ? WHERE id = ? AND status = 'claimed' AND assigned_agent_id = ?",
                [$now, $id, $actor->id],
            );
            if ($updated === 0) {
                throw new ChatProblem('The conversation is not claimed by you.', 409, 'conversation-not-claimed');
            }
            DB::table('assignments')->where('conversation_id', $id)->whereNull('released_at')
                ->update(['released_at' => $now, 'reason' => AssignmentReason::Released->value]);
            event(new QueueUpdated($id, QueueUpdated::RELEASED));
            event(new ConversationUpdated($id));

            return DB::table('conversations')->where('id', $id)->first() ?? throw ChatProblem::notFound();
        });

        $this->queue->enqueue($id, (string) $row->created_at);
        $this->unread->forget($id, $actor->id);
        ChatLog::audit('chat_conversation_released', $id, $actor->id);

        return $row;
    }
}
