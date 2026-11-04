<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('po.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'requested_for_employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('is_active', true)],
            'requested_for_department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'integer', 'distinct', Rule::exists('items', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D', 'not_in:0,0.0,0.00,0.000'],
            'items.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tax_rate_ids' => ['sometimes', 'array', 'max:30'],
            'tax_rate_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tax_rates', 'id')],
        ];
    }
}
