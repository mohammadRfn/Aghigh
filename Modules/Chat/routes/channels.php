<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Modules\Chat\Services\ChannelAuthorizer;

/*
 | Chat private channels, authorized through the single Task 7 route POST /api/broadcasting/auth
 | (middleware api, auth:api, throttle.sliding:broadcasting-auth) with the stateless JWT guard.
 | Wire names: private-conversation.{uuid}, private-agents.queue. Never cast user ids to int (UUIDv7).
 | Loaded by ChatServiceProvider; the root routes/channels.php only points here.
 */
Broadcast::channel(
    'conversation.{conversationId}',
    fn (mixed $user, string $conversationId): bool => app(ChannelAuthorizer::class)->conversation($conversationId),
    ['guards' => ['api']],
);

Broadcast::channel(
    'agents.queue',
    fn (mixed $user): bool => app(ChannelAuthorizer::class)->agentsQueue(),
    ['guards' => ['api']],
);
