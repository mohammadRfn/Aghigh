<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Catalog\Exceptions\CatalogProblem;

final class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $q = $this->input('q');
        if (is_string($q)) {
            $this->merge(['q' => mb_strtolower(trim($q), 'UTF-8')]);
        }
        $cursor = $this->input('cursor');
        if ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 4096)) {
            throw new CatalogProblem('Invalid catalog cursor.', 400, 'invalid-cursor');
        }
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'min:3', 'max:100'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:'.config('catalog.price_max_minor')],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:'.config('catalog.price_max_minor')],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('catalog.per_page_max')],
            'cursor' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, int|string|null> */
    public function normalized(): array
    {
        $data = $this->validated();
        $params = [
            'category' => isset($data['category']) ? strtolower((string) $data['category']) : null,
            'q' => empty($data['q']) ? null : (string) $data['q'],
            'min_price' => isset($data['min_price']) ? (int) $data['min_price'] : null,
            'max_price' => isset($data['max_price']) ? (int) $data['max_price'] : null,
            'sort' => $data['sort'] ?? 'newest',
            'per_page' => isset($data['per_page']) ? (int) $data['per_page'] : (int) config('catalog.per_page'),
            'cursor' => $data['cursor'] ?? null,
        ];
        if ($params['min_price'] !== null && $params['max_price'] !== null && $params['min_price'] > $params['max_price']) {
            throw new CatalogProblem('max_price must be greater than or equal to min_price.');
        }
        ksort($params);

        return $params;
    }
}
