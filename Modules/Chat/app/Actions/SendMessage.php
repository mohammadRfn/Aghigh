<?php

declare(strict_types=1);

namespace Modules\Chat\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Events\MessagePersisted;
use Modules\Chat\Events\MessageSent;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Services\UnreadCounters;
use Modules\Chat\Support\ChatTime;
use Modules\Chat\Support\Sql;
use Modules\Identity\Contracts\CurrentUser;
use Throwable;

/**
 * POST /conversations/{id}/messages. Sequence allocation (ADR 0009):
 *   1. look up (conversation_id, client_message_id) first and answer the existing message (200);
 *   2. one short transaction: UPDATE conversations SET last_seq = last_seq + 1 ... RETURNING last_seq (the row lock
 *      serializes senders per conversation; a rollback also rolls the counter back, so seq has no gaps) + INSERT;
 *   3. a unique violation aborts the whole Postgres transaction, so it is caught OUTSIDE DB::transaction
 *      (the PlaceOrder pattern), the existing row re-selected and answered with 200.
 * Same client_message_id with another body (or another sender) -> 409 client-message-id-reuse.
 * MessageSent / MessagePersisted are ShouldDispatchAfterCommit: a rolled-back send broadcasts nothing.
 */
final class SendMessage
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly UnreadCounters $unread,
    ) {}

    /** @return array{0: object, 1: bool} [message row, created] */
    public function __invoke(CurrentUser $actor, string $conversationId, string $body, string $clientMessageId): array
    {
        $body = self::normalize($body);
        $max = (int) config('chat.body_max_chars', 2000);
        if ($body === '' || mb_strlen($body) > $max) {
            throw ValidationException::withMessages(['body' => ["The body must contain 1 to {$max} characters."]]);
        }

        $conversation = $this->policy->find($actor, $conversationId) ?? throw ChatProblem::notFound();
        if ((string) $conversation->status === ConversationStatus::Closed->value) {
            throw ChatProblem::closed();
        }
        if (! $this->policy->canPost($actor, $conversation)) {
            throw ChatProblem::forbidden('You cannot post in this conversation.');
        }
        $id = (string) $conversation->id;

        if (($existing = $this->existing($id, $clientMessageId)) !== null) {
            return [$this->replay($existing, $actor, $body), false];
        }

        try {
            [$message, $otherParty] = DB::transaction(fn (): array => $this->persist($actor, $id, $body, $clientMessageId));
        } catch (Throwable $error) {
            if (! Sql::isUniqueViolation($error) || ($existing = $this->existing($id, $clientMessageId)) === null) {
                throw $error;
            }

            return [$this->replay($existing, $actor, $body), false];
        }

        $this->unread->incrementFor($id, $otherParty); // after commit, best effort (DB is the truth)
        $this->unread->forget($id, $actor->id); // the sender just read up to their own message: drop the stale cached counter

        return [$message, true];
    }

    public static function normalize(string $body): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{200B}]+|[\s\x{FEFF}\x{200B}]+$/u', '', $body);
    }

    /** @return array{0: object, 1: string|null} */
    private function persist(CurrentUser $actor, string $conversationId, string $body, string $clientMessageId): array
    {
        $now = ChatTime::db(ChatTime::now());
        if ($this->policy->isCustomer($actor)) {
            $guard = "customer_id = ? AND status IN ('waiting', 'claimed')";
        } else {
            $guard = "status = 'claimed' AND assigned_agent_id = ?";
        }
        $row = Sql::returning(
            "UPDATE conversations SET last_seq = last_seq + 1, updated_at = ? WHERE id = ? AND {$guard} RETURNING last_seq, customer_id, assigned_agent_id",
            [$now, $conversationId, $actor->id],
        );
        if ($row === null) {
            $status = DB::table('conversations')->where('id', $conversationId)->value('status');
            throw $status === ConversationStatus::Closed->value ? ChatProblem::closed() : ChatProblem::forbidden('You cannot post in this conversation.');
        }

        $message = (object) [
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId, 'seq' => (int) $row->last_seq,
            'sender_id' => $actor->id, 'sender_role' => $actor->role, 'body' => $body,
            'client_message_id' => $clientMessageId, 'created_at' => $now,
        ];
        DB::table('messages')->insert((array) $message);

        // The sender has read their own message: keeps unread = last_seq - last_read_seq exact for both parties.
        $greatest = Sql::greatest('conversation_reads.last_read_seq', 'excluded.last_read_seq');
        DB::statement(
            "INSERT INTO conversation_reads (conversation_id, user_id, last_read_seq, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (conversation_id, user_id) DO UPDATE SET last_read_seq = {$greatest}, updated_at = excluded.updated_at",
            [$conversationId, $actor->id, $message->seq, $now],
        );

        event(new MessageSent($conversationId, $message->id));
        event(new MessagePersisted($conversationId, $message->id, $message->seq, $actor->id, $actor->role));

        $other = $this->policy->isCustomer($actor) ? $row->assigned_agent_id : $row->customer_id;

        return [$message, $other === null ? null : (string) $other];
    }

    /** @phpstan-impure reads the current row: never reuse an earlier result */
    private function existing(string $conversationId, string $clientMessageId): ?object
    {
        $row = DB::table('messages')->where('conversation_id', $conversationId)->where('client_message_id', $clientMessageId)->first();

        return is_object($row) ? $row : null;
    }

    private function replay(object $existing, CurrentUser $actor, string $body): object
    {
        if ((string) $existing->sender_id !== $actor->id || (string) $existing->body !== $body) {
            throw new ChatProblem('This client_message_id was already used for a different message.', 409, 'client-message-id-reuse');
        }

        return $existing;
    }
}
