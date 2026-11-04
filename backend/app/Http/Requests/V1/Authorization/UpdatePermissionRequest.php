<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Authorization;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
