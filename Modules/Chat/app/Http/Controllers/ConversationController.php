<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Actions\CloseConversation;
use Modules\Chat\Actions\MarkRead;
use Modules\Chat\Actions\OpenConversation;
use Modules\Chat\Actions\ReleaseConversation;
use Modules\Chat\Actions\SignalTyping;
use Modules\Chat\Http\Requests\ListConversationsRequest;
use Modules\Chat\Http\Requests\MarkReadRequest;
use Modules\Chat\Http\Resources\ChatPresenter;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Services\UnreadCounters;
use Modules\Identity\Contracts\CurrentUser;

#[Group('Chat')]
final class ConversationController
{
    public function store(CurrentUser $actor, OpenConversation $open): JsonResponse
    {
        [$row, $created] = $open($actor);

        return new JsonResponse(['data' => ChatPresenter::conversation($row)], $created ? 201 : 200, [
            'Location' => route('api.v1.chat.messages.index', ['conversation' => $row->id], false),
        ]);
    }

    /** Own conversations (customer) or assigned ones (support agent); admins get an empty list by default. */
    public function index(ListConversationsRequest $request, CurrentUser $actor, ConversationPolicy $policy, UnreadCounters $unread): JsonResponse
    {
        $perPage = $request->perPage();
        $column = match (true) {
            $policy->isCustomer($actor) => 'customer_id',
            $policy->isAgent($actor) => 'assigned_agent_id',
            default => null,
        };
        $rows = [];
        if ($column !== null) {
            $query = DB::table('conversations')->where($column, $actor->id)->orderByDesc('id')->limit($perPage + 1);
            if (($status = $request->status()) !== null) {
                $query->where('status', $status);
            }
            if (($cursor = $request->cursor()) !== null) {
                $query->where('id', '<', $cursor);
            }
            $rows = $query->get()->all();
        }
        $page = array_slice($rows, 0, $perPage);
        $counts = $unread->forUser($actor->id, $page);
        $last = end($page);

        return new JsonResponse([
            'data' => array_map(fn (object $row): array => ChatPresenter::conversation($row, $counts[(string) $row->id] ?? 0), $page),
            'meta' => [
                'per_page' => $perPage,
                'next_cursor' => count($rows) > $perPage && $last !== false ? ListConversationsRequest::encodeCursor((string) $last->id) : null,
            ],
        ]);
    }

    public function read(MarkReadRequest $request, string $conversation, CurrentUser $actor, MarkRead $markRead): JsonResponse
    {
        return new JsonResponse(['data' => $markRead($actor, $conversation, $request->seq())]);
    }

    public function typing(string $conversation, CurrentUser $actor, SignalTyping $typing): JsonResponse
    {
        return new JsonResponse(['data' => ['broadcast' => $typing($actor, $conversation)]], 202);
    }

    public function close(string $conversation, CurrentUser $actor, CloseConversation $close): JsonResponse
    {
        return new JsonResponse(['data' => ChatPresenter::conversation($close($actor, $conversation))]);
    }

    public function release(string $conversation, CurrentUser $actor, ReleaseConversation $release): JsonResponse
    {
        return new JsonResponse(['data' => ChatPresenter::conversation($release($actor, $conversation))]);
    }
}
