<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Authorization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SavePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:125',
                'regex:/^[a-z][a-z0-9_.-]*$/',
                Rule::unique('permissions', 'name')
                    ->where('guard_name', 'web'),
            ],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
