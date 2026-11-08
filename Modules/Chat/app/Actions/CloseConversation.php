<?php

declare(strict_types=1);

namespace Modules\Chat\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\AssignmentReason;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Events\ConversationClosed;
use Modules\Chat\Events\ConversationUpdated;
use Modules\Chat\Events\QueueUpdated;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Services\SupportQueue;
use Modules\Chat\Support\ChatLog;
use Modules\Chat\Support\ChatTime;
use Modules\Identity\Contracts\CurrentUser;

/** POST /conversations/{id}/close: the customer, or the assigned agent of a claimed conversation. */
final class CloseConversation
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly SupportQueue $queue,
    ) {}

    public function __invoke(CurrentUser $actor, string $conversationId): object
    {
        $conversation = $this->policy->find($actor, $conversationId) ?? throw ChatProblem::notFound();
        if ((string) $conversation->status === ConversationStatus::Closed->value) {
            throw ChatProblem::closed();
        }
        if (! $this->policy->canClose($actor, $conversation)) {
            throw ChatProblem::forbidden('You cannot close this conversation.');
        }
        $id = (string) $conversation->id;

        $row = DB::transaction(function () use ($actor, $id): object {
            $now = ChatTime::db(ChatTime::now());
            $guard = $this->policy->isCustomer($actor) ? 'customer_id = ?' : "status = 'claimed' AND assigned_agent_id = ?";
            $updated = DB::update(
                "UPDATE conversations SET status = 'closed', closed_at = ?, updated_at = ? WHERE id = ? AND status <> 'closed' AND {$guard}",
                [$now, $now, $id, $actor->id],
            );
            if ($updated === 0) {
                $status = DB::table('conversations')->where('id', $id)->value('status');
                throw $status === ConversationStatus::Closed->value ? ChatProblem::closed() : ChatProblem::forbidden('You cannot close this conversation.');
            }
            DB::table('assignments')->where('conversation_id', $id)->whereNull('released_at')
                ->update(['released_at' => $now, 'reason' => AssignmentReason::Closed->value]);
            event(new ConversationClosed($id, $actor->id, $actor->role));
            event(new QueueUpdated($id, QueueUpdated::CLOSED));
            event(new ConversationUpdated($id));

            return DB::table('conversations')->where('id', $id)->first() ?? throw ChatProblem::notFound();
        });

        $this->queue->remove($id);
        ChatLog::audit('chat_conversation_closed', $id, $actor->id, ['role' => $actor->role]);

        return $row;
    }
}
