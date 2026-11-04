<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreEmployeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $data = [];

        if (is_string($this->input('employee_number'))) {
            $data['employee_number'] = Str::upper(trim($this->input('employee_number')));
        }

        if (is_string($this->input('name'))) {
            $data['name'] = Str::squish($this->input('name'));
        }

        if (is_string($this->input('email'))) {
            $data['email'] = Str::lower(trim($this->input('email')));
        }

        $this->merge($data);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'department_id' => [
                'required', 'integer',
                Rule::exists('departments', 'id')->where('is_active', true),
            ],
            'user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::unique('employees', 'user_id'),
            ],
            'employee_number' => [
                'required', 'string', 'max:50',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('employees', 'employee_number'),
            ],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
