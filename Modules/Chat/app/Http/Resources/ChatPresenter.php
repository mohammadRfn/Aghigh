<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Resources;

use Modules\Chat\Support\ChatTime;

/** Wire shapes shared by REST responses and broadcast payloads (docs/chat-protocol.md). */
final class ChatPresenter
{
    /** @return array<string, mixed> */
    public static function conversation(object $row, ?int $unread = null): array
    {
        $data = [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'customer_id' => (string) $row->customer_id,
            'assigned_agent_id' => $row->assigned_agent_id === null ? null : (string) $row->assigned_agent_id,
            'last_seq' => (int) $row->last_seq,
            'created_at' => ChatTime::wire($row->created_at),
            'updated_at' => ChatTime::wire($row->updated_at),
            'closed_at' => ChatTime::wire($row->closed_at),
        ];
        if ($unread !== null) {
            $data['unread'] = $unread;
        }

        return $data;
    }

    /**
     * The body is returned raw (as stored, trimmed): clients must escape it when rendering.
     *
     * @return array<string, mixed>
     */
    public static function message(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'conversation_id' => (string) $row->conversation_id,
            'seq' => (int) $row->seq,
            'sender_id' => (string) $row->sender_id,
            'sender_role' => (string) $row->sender_role,
            'body' => (string) $row->body,
            'client_message_id' => (string) $row->client_message_id,
            'created_at' => ChatTime::wire($row->created_at),
        ];
    }
}
