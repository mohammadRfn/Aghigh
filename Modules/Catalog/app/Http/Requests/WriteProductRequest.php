<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Catalog\Policies\CatalogPolicy;
use Modules\Identity\Contracts\CurrentUser;

final class WriteProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CatalogPolicy::class)->authorize(app(CurrentUser::class));

        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $id = $this->route('id');

        return [
            'category_id' => [$required, 'required', 'uuid', 'exists:categories,id'],
            'sku' => [$required, 'required', 'string', 'max:80', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/', Rule::unique('products', 'sku')->ignore($id)],
            'name' => [$required, 'required', 'string', 'max:200'],
            'slug' => [$required, 'required', 'string', 'max:200', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::unique('products', 'slug')->ignore($id)],
            'description' => ['sometimes', 'string', 'max:20000'],
            'price_minor' => [$required, 'required', 'integer', 'min:1', 'max:'.config('catalog.price_max_minor')],
            'currency' => [$required, 'required', 'string', 'size:3', Rule::in([config('catalog.currency')])],
            'status' => ['sometimes', 'required', Rule::in(['active', 'draft', 'archived'])],
            'images' => ['sometimes', 'array', 'max:'.config('catalog.images_max')],
            'images.*' => ['array:path,width,height,position,alt'],
            'images.*.path' => ['required', 'string', 'max:500', 'regex:/\A(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9][A-Za-z0-9._-]*\z/', 'not_regex:/\.\./'],
            'images.*.width' => ['required', 'integer', 'min:1', 'max:20000'],
            'images.*.height' => ['required', 'integer', 'min:1', 'max:20000'],
            'images.*.position' => ['required', 'integer', 'min:0', 'max:1000000', 'distinct'],
            'images.*.alt' => ['required', 'string', 'max:250'],
        ];
    }
}
