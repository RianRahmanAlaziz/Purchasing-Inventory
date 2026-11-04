<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('po.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'integer', Rule::exists('suppliers', 'id')],
            'requested_for_employee_id' => ['sometimes', 'required', 'integer', Rule::exists('employees', 'id')],
            'requested_for_department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')],
            'order_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'expected_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'integer', 'distinct', Rule::exists('items', 'id')],
            'items.*.quantity' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D', 'not_in:0,0.0,0.00,0.000'],
            'items.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tax_rate_ids' => ['sometimes', 'array', 'max:30'],
            'tax_rate_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tax_rates', 'id')],
        ];
    }
}
