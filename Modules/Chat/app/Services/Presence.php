<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\State\ChatState;
use Modules\Chat\Support\ChatTime;
use Modules\Identity\Contracts\CurrentUser;

/**
 * Presence = sorted set `chat:presence` (member = user id, score = last heartbeat ms); online = ZRANGEBYSCORE over the
 * TTL window. No KEYS / SCAN. Agents and admins see who is online; customers only learn whether any agent is online.
 */
final class Presence
{
    public function __construct(
        private readonly ChatState $state,
        private readonly ConversationPolicy $policy,
        private readonly ConnectionCap $connections,
    ) {}

    /** @return array<string, mixed> */
    public function heartbeat(CurrentUser $actor, ?string $socketId): array
    {
        $now = ChatTime::nowMs();
        $ttlMs = $this->ttlMs();
        $this->state->presenceTouch($actor->id, $this->policy->isAgent($actor), $now, $now - 2 * $ttlMs);
        $refreshed = $socketId !== null && $this->connections->refresh($actor->id, $socketId);

        return $this->view($actor) + ['socket_refreshed' => $refreshed];
    }

    /** @return array<string, mixed> */
    public function view(CurrentUser $actor): array
    {
        $since = ChatTime::nowMs() - $this->ttlMs();
        $data = ['agents_online' => $this->state->agentsOnline($since) > 0, 'ttl_seconds' => (int) config('chat.presence_ttl_seconds', 60)];
        if ($this->policy->seesPresenceList($actor)) {
            $data['online_user_ids'] = $this->state->presenceOnline($since, (int) config('chat.presence_list_max', 500));
        }

        return $data;
    }

    private function ttlMs(): int
    {
        return max(1, (int) config('chat.presence_ttl_seconds', 60)) * 1000;
    }
}
