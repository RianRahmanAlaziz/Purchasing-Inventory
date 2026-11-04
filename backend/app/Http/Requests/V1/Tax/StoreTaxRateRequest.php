<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tax;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreTaxRateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('label'))) {
            $this->merge(['label' => Str::squish($this->input('label'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('taxes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'tax_type_id' => ['required', 'integer', Rule::exists('tax_types', 'id')],
            'label' => ['required', 'string', 'max:160'],
            'rate_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,4'],
            'taxable_base_factor' => [
                'sometimes', 'required', 'numeric', 'gt:0', 'lte:100', 'decimal:0,8',
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => [
                'sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from',
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
