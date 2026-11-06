<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Authorization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'roles' => [
                'present',
                'array',
                'max:10',
            ],
            'roles.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('roles', 'name')
                    ->where('guard_name', 'web'),
            ],
        ];
    }
}
