<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Modules\Chat\Http\Requests\HeartbeatRequest;
use Modules\Chat\Services\Presence;
use Modules\Chat\Support\ChatLog;
use Modules\Identity\Contracts\CurrentUser;
use Throwable;

/** Presence fails open: a state-Redis outage answers 200 with degraded=true instead of an error. */
#[Group('Chat')]
final class PresenceController
{
    public function heartbeat(HeartbeatRequest $request, CurrentUser $actor, Presence $presence): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $presence->heartbeat($actor, $request->socketId())]);
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'heartbeat']);

            return new JsonResponse(['data' => ['agents_online' => null, 'degraded' => true]]);
        }
    }

    public function show(CurrentUser $actor, Presence $presence): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $presence->view($actor)]);
        } catch (Throwable $error) {
            ChatLog::warning('chat_state_unavailable', $error, ['op' => 'presence']);

            return new JsonResponse(['data' => ['agents_online' => null, 'degraded' => true]]);
        }
    }
}
