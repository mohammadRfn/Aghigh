<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Support\ChatLog;
use Modules\Identity\Contracts\CurrentUser;
use Throwable;

/**
 * Private channel authorization (called from Modules/Chat/routes/channels.php through the single Task 7 route
 * POST /api/broadcasting/auth: api, auth:api, throttle.sliding:broadcasting-auth).
 * Malformed, unknown and forbidden conversation ids give the same `false` (Laravel answers 403): no existence leak.
 * After the policy passes, the approximate per-user connection cap is checked (fail closed on backend errors).
 */
final class ChannelAuthorizer
{
    public function __construct(
        private readonly ConversationPolicy $policy,
        private readonly ConnectionCap $cap,
    ) {}

    public function conversation(string $conversationId): bool
    {
        $actor = $this->actor();
        $channel = 'conversation.'.$conversationId;
        if ($actor === null || $this->policy->find($actor, $conversationId) === null) {
            ChatLog::channelDenied($actor?->id, $channel, 'forbidden');

            return false;
        }

        return $this->admit($actor, $channel);
    }

    public function agentsQueue(): bool
    {
        $actor = $this->actor();
        if ($actor === null || ! $this->policy->isAgent($actor)) {
            ChatLog::channelDenied($actor?->id, 'agents.queue', 'forbidden');

            return false;
        }

        return $this->admit($actor, 'agents.queue');
    }

    private function admit(CurrentUser $actor, string $channel): bool
    {
        $socketId = request()->input('socket_id');
        if (! ConnectionCap::validSocketId($socketId)) {
            ChatLog::channelDenied($actor->id, $channel, 'invalid-socket');

            return false;
        }
        try {
            $admitted = $this->cap->admit($actor->id, (string) $socketId);
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'connection_admit']);
            ChatLog::channelDenied($actor->id, $channel, 'backend-unavailable');

            return false;
        }
        if (! $admitted) {
            ChatLog::channelDenied($actor->id, $channel, 'connection-cap');
        }

        return $admitted;
    }

    private function actor(): ?CurrentUser
    {
        try {
            return app(CurrentUser::class);
        } catch (Throwable) {
            return null;
        }
    }
}
