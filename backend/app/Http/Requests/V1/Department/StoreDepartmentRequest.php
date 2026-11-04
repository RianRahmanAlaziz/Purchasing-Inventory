<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreDepartmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $data = [];

        if (is_string($this->input('code'))) {
            $data['code'] = Str::upper(trim($this->input('code')));
        }

        if (is_string($this->input('name'))) {
            $data['name'] = Str::squish($this->input('name'));
        }

        $this->merge($data);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('departments.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:40',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('departments', 'code'),
            ],
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('departments', 'name'),
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
