<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tax;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreTaxTypeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $values = [];
        if (is_string($this->input('code'))) {
            $values['code'] = Str::upper(trim($this->input('code')));
        }
        if (is_string($this->input('name'))) {
            $values['name'] = Str::squish($this->input('name'));
        }
        $this->merge($values);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('taxes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50',
                'regex:/^[A-Z][A-Z0-9_-]*$/',
                Rule::unique('tax_types', 'code'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'direction' => ['required', Rule::in(['addition', 'withholding'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
