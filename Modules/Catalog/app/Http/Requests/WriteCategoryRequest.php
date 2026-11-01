<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Catalog\Policies\CatalogPolicy;
use Modules\Identity\Contracts\CurrentUser;

final class WriteCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CatalogPolicy::class)->authorize(app(CurrentUser::class));

        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:160'],
            'slug' => [$required, 'required', 'string', 'max:160', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::unique('categories', 'slug')->ignore($this->route('id'))],
            'position' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', 'exists:categories,id'],
        ];
    }
}
