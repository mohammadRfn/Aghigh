<?php

declare(strict_types=1);

namespace Modules\Ordering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders body. Only product ids and quantities are accepted: money is never read from the
 * client (unknown fields such as prices or totals are dropped), and a product may appear once.
 */
final class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('ordering.max_items')],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.(int) config('ordering.max_quantity')],
        ];
    }

    /**
     * Normalized so the idempotency hash does not depend on item order or id case.
     *
     * @return list<array{product_id: string, quantity: int}>
     */
    public function items(): array
    {
        /** @var list<array{product_id: string, quantity: int|string}> $raw */
        $raw = $this->validated('items');
        $items = array_map(
            fn (array $item): array => ['product_id' => strtolower($item['product_id']), 'quantity' => (int) $item['quantity']],
            $raw,
        );
        usort($items, fn (array $a, array $b): int => strcmp($a['product_id'], $b['product_id']));

        return $items;
    }
}
