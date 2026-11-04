<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Authorization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

final class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    public function rules(): array
    {
        $role = $this->route('role');

        $unique = Rule::unique('roles', 'name')
            ->where('guard_name', 'web');

        if ($role instanceof Role) {
            $unique->ignore($role->getKey());
        }

        return [
            'name' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                $unique,
            ],
            'permissions' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'array',
            ],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('permissions', 'name')
                    ->where('guard_name', 'web'),
            ],
        ];
    }
}
