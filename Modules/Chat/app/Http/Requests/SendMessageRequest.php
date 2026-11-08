<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // `max` on a string counts characters (mb_strlen), not bytes. The action re-checks after its own trim.
            'body' => ['required', 'string', 'max:'.(int) config('chat.body_max_chars', 2000)],
            // Same format as Idempotency-Key: 16 to 64 URL-safe characters.
            'client_message_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/D'],
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }

    public function clientMessageId(): string
    {
        return (string) $this->validated('client_message_id');
    }
}
