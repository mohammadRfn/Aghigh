<?php

declare(strict_types=1);

namespace Modules\Ordering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Ordering\Enums\OrderStatus;

/** GET /orders query: keyset pagination over the order id (uuid7 = time-ordered). Excess page sizes are rejected, not clamped. */
final class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('ordering.per_page_max')],
            'cursor' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::enum(OrderStatus::class)],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('ordering.per_page'));
    }

    public function status(): ?string
    {
        $status = $this->validated('status');

        return is_string($status) ? $status : null;
    }

    /** The order id after which the next page starts, or null for the first page. */
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

    public static function encodeCursor(string $orderId): string
    {
        return rtrim(strtr(base64_encode($orderId), '+/', '-_'), '=');
    }
}
