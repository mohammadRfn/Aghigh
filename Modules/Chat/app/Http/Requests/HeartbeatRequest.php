<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // Pusher socket id ("123.456"): refreshes this socket's slot in the per-user connection cap.
        return ['socket_id' => ['sometimes', 'string', 'regex:/^\d{1,20}\.\d{1,20}$/D']];
    }

    public function socketId(): ?string
    {
        $id = $this->validated('socket_id');

        return is_string($id) ? $id : null;
    }
}
