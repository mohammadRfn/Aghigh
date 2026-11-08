<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Chat\Actions\ClaimNextConversation;
use Modules\Chat\Http\Resources\ChatPresenter;
use Modules\Identity\Contracts\CurrentUser;

#[Group('Chat')]
final class SupportQueueController
{
    /** 200 with the claimed conversation, 204 when the queue is empty. */
    public function claim(CurrentUser $actor, ClaimNextConversation $claim): JsonResponse|Response
    {
        $row = $claim($actor);

        return $row === null ? response()->noContent() : new JsonResponse(['data' => ChatPresenter::conversation($row, 0)]);
    }
}
