<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarkReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['last_read_seq' => ['sometimes', 'integer', 'min:0']];
    }

    public function seq(): ?int
    {
        return $this->has('last_read_seq') ? (int) $this->validated('last_read_seq') : null;
    }
}
