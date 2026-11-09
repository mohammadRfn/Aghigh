<?php

declare(strict_types=1);

namespace Modules\Chat\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Events\QueueUpdated;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Services\SupportQueue;
use Modules\Chat\Support\ChatTime;
use Modules\Chat\Support\Sql;
use Modules\Identity\Contracts\CurrentUser;
use Throwable;

/**
 * POST /conversations (customer). One open conversation per customer, guaranteed by the partial unique index
 * conversations_one_open_per_customer. An existing open conversation is returned (created=false -> 200).
 * The unique violation of a parallel request is caught OUTSIDE the transaction (Postgres aborted it), then re-selected.
 */
final class OpenConversation
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly SupportQueue $queue,
    ) {}

    /** @return array{0: object, 1: bool} [conversation row, created] */
    public function __invoke(CurrentUser $actor): array
    {
        if (! $this->policy->isCustomer($actor)) {
            throw ChatProblem::forbidden('Only customers open support conversations.');
        }
        if (($open = $this->openFor($actor->id)) !== null) {
            return [$open, false];
        }

        try {
            $row = DB::transaction(function () use ($actor): object {
                $now = ChatTime::db(ChatTime::now());
                $row = (object) [
                    'id' => (string) Str::uuid7(), 'customer_id' => $actor->id, 'status' => ConversationStatus::Waiting->value,
                    'assigned_agent_id' => null, 'last_seq' => 0, 'closed_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
                DB::table('conversations')->insert((array) $row);
                event(new QueueUpdated($row->id, QueueUpdated::QUEUED));

                return $row;
            });
        } catch (Throwable $error) {
            if (! Sql::isUniqueViolation($error) || ($open = $this->openFor($actor->id)) === null) {
                throw $error;
            }

            return [$open, false];
        }

        $this->queue->enqueue($row->id, $row->created_at);

        return [$row, true];
    }

    /** @phpstan-impure reads the current row: never reuse an earlier result */
    private function openFor(string $customerId): ?object
    {
        $row = DB::table('conversations')->where('customer_id', $customerId)->where('status', '<>', ConversationStatus::Closed->value)->first();

        return is_object($row) ? $row : null;
    }
}
