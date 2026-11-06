<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SaveCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('companies.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120',
                Rule::unique('companies')->ignore($this->route('company'))],
            'address' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:1400000', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! preg_match('#^data:(image/(?:png|jpeg|webp));base64,(.+)$#D', $value, $matches)) {
                    $fail('Logo harus berupa PNG, JPG, atau WebP.');

                    return;
                }
                $bytes = base64_decode($matches[2], true);
                $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                if ($bytes === false || strlen($bytes) > 1048576 || $info === false || $info['mime'] !== $matches[1]) {
                    $fail('Logo tidak valid atau melebihi 1 MB.');
                }
            }],
        ];
    }
}
