<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Chat\Enums\ConversationStatus;

/** Keyset pagination on the UUIDv7 id (newest first), cursor encoded like Ordering's. */
final class ListConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('chat.per_page_max', 50)],
            'cursor' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::enum(ConversationStatus::class)],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('chat.per_page', 20));
    }

    public function status(): ?string
    {
        $status = $this->validated('status');

        return is_string($status) ? $status : null;
    }

    public function cursor(): ?string
    {
        $raw = $this->validated('cursor');
        if ($raw === null) {
            return null;
        }
        $id = base64_decode(strtr((string) $raw, '-_', '+/'), true);
        if ($id === false || ! Str::isUuid($id)) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is invalid.']]);
        }

        return strtolower($id);
    }

    public static function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }
}
