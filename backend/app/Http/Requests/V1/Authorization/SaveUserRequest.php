<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Authorization;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') && $this->user()->can('roles.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $email = Rule::unique('users', 'email');

        if ($user instanceof User) {
            $email->ignore($user->getKey());
        }

        $presence = $this->isMethod('post') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$presence, 'string', 'max:255'],
            'email' => [...$presence, 'string', 'email', 'max:255', $email],
            'password' => [...$presence, 'string', 'min:12', 'regex:/\A[^\x00]{1,72}\z/', 'confirmed'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => $this->isMethod('post') ? ['sometimes', 'array', 'max:10'] : ['prohibited'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Panjang kata sandi maksimal 72 byte dan tidak boleh mengandung karakter null.',
        ];
    }
}
