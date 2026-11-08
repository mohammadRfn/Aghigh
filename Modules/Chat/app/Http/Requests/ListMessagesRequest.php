<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Two modes: `after_seq` = ascending gap-filling; otherwise newest first with an optional `before_seq` cursor.
 * `limit` above the hard maximum (200) is rejected with 422, never clamped.
 */
final class ListMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'after_seq' => ['sometimes', 'integer', 'min:0', 'prohibits:before_seq'],
            'before_seq' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('chat.messages.max_limit', 200)],
        ];
    }

    public function afterSeq(): ?int
    {
        return $this->has('after_seq') ? (int) $this->validated('after_seq') : null;
    }

    public function beforeSeq(): ?int
    {
        return $this->has('before_seq') ? (int) $this->validated('before_seq') : null;
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', config('chat.messages.default_limit', 50));
    }
}
