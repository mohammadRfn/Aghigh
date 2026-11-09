<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Actions\SendMessage;
use Modules\Chat\Exceptions\ChatProblem;
use Modules\Chat\Http\Requests\ListMessagesRequest;
use Modules\Chat\Http\Requests\SendMessageRequest;
use Modules\Chat\Http\Resources\ChatPresenter;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Identity\Contracts\CurrentUser;

#[Group('Chat')]
final class MessageController
{
    public function store(SendMessageRequest $request, string $conversation, CurrentUser $actor, SendMessage $send): JsonResponse
    {
        [$message, $created] = $send($actor, $conversation, $request->body(), $request->clientMessageId());

        return new JsonResponse(['data' => ChatPresenter::message($message)], $created ? 201 : 200, $created ? [] : ['Idempotency-Replayed' => 'true']);
    }

    /**
     * ?after_seq=N&limit= : ascending gap-filling, repeat until has_more=false.
     * otherwise          : newest first, ?before_seq= for scrollback.
     * meta.last_seq is the conversation counter read BEFORE the page (a lower bound: newer messages may already exist).
     */
    public function index(ListMessagesRequest $request, string $conversation, CurrentUser $actor, ConversationPolicy $policy): JsonResponse
    {
        $row = $policy->find($actor, $conversation) ?? throw ChatProblem::notFound();
        $limit = $request->limit();
        $query = DB::table('messages')->where('conversation_id', $row->id)->limit($limit + 1);
        if (($after = $request->afterSeq()) !== null) {
            $query->where('seq', '>', $after)->orderBy('seq');
        } else {
            if (($before = $request->beforeSeq()) !== null) {
                $query->where('seq', '<', $before);
            }
            $query->orderByDesc('seq');
        }
        $messages = $query->get()->all();

        return new JsonResponse([
            'data' => array_map(ChatPresenter::message(...), array_slice($messages, 0, $limit)),
            'meta' => [
                'order' => $after !== null ? 'asc' : 'desc',
                'limit' => $limit,
                'has_more' => count($messages) > $limit,
                'last_seq' => (int) $row->last_seq,
            ],
        ]);
    }
}
