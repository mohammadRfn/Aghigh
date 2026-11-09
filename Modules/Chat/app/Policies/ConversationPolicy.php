<?php

declare(strict_types=1);

namespace Modules\Chat\Policies;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Identity\Contracts\CurrentUser;

/**
 * Who may see and act on a conversation. Fed only by Identity's CurrentUser contract (id, role, emailVerified).
 * - customer: only their own conversations
 * - support_agent: only conversations currently assigned to them (closed ones they handled stay visible)
 * - admin: none by default (privacy of support conversations; documented in docs/chat-protocol.md)
 * A malformed, unknown or foreign id all resolve to null: callers answer the same 404 (REST) or 403 (channels).
 */
final class ConversationPolicy
{
    public const CUSTOMER = 'customer';

    public const AGENT = 'support_agent';

    public const ADMIN = 'admin';

    public function find(CurrentUser $actor, string $id): ?object
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $row = DB::table('conversations')->where('id', strtolower($id))->first();

        return is_object($row) && $this->canView($actor, $row) ? $row : null;
    }

    public function canView(CurrentUser $actor, object $conversation): bool
    {
        return match ($actor->role) {
            self::CUSTOMER => (string) $conversation->customer_id === $actor->id,
            self::AGENT => $conversation->assigned_agent_id !== null && (string) $conversation->assigned_agent_id === $actor->id,
            default => false,
        };
    }

    public function canPost(CurrentUser $actor, object $conversation): bool
    {
        $status = (string) $conversation->status;

        return match ($actor->role) {
            self::CUSTOMER => (string) $conversation->customer_id === $actor->id
                && in_array($status, [ConversationStatus::Waiting->value, ConversationStatus::Claimed->value], true),
            self::AGENT => $status === ConversationStatus::Claimed->value && (string) $conversation->assigned_agent_id === $actor->id,
            default => false,
        };
    }

    public function canClose(CurrentUser $actor, object $conversation): bool
    {
        return $this->canView($actor, $conversation)
            && ($actor->role === self::CUSTOMER || (string) $conversation->status === ConversationStatus::Claimed->value);
    }

    public function isCustomer(CurrentUser $actor): bool
    {
        return $actor->role === self::CUSTOMER;
    }

    public function isAgent(CurrentUser $actor): bool
    {
        return $actor->role === self::AGENT;
    }

    public function seesPresenceList(CurrentUser $actor): bool
    {
        return in_array($actor->role, [self::AGENT, self::ADMIN], true);
    }
}
