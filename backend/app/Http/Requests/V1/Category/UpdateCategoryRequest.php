<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Category;

use App\Domain\Category\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('code'))) {
            $normalized['code'] = Str::upper(trim($this->input('code')));
        }

        if (is_string($this->input('name'))) {
            $normalized['name'] = Str::squish($this->input('name'));
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('categories.manage') ?? false;
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $id = $category instanceof Category ? $category->getKey() : null;

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:40',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('categories', 'code')->ignore($id),
            ],
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                Rule::unique('categories', 'name')->ignore($id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
