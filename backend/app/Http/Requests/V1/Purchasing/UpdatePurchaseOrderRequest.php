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
            'company_id' => ['sometimes', 'nullable', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'supplier_address' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tax_scope' => ['sometimes', Rule::in(['order', 'line'])],
            'supplier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'supplier_name' => ['sometimes', 'required', 'string', 'max:200'],
            'requested_for_employee_id' => ['sometimes', 'required', 'integer', Rule::exists('employees', 'id')],
            'requested_for_department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')],
            'order_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'expected_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required_without:items.*.name', 'nullable', 'integer', 'distinct', Rule::exists('items', 'id')],
            'items.*.name' => ['required_without:items.*.item_id', 'nullable', 'string', 'max:200'],
            'items.*.category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D', 'not_in:0,0.0,0.00,0.000'],
            'items.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items.*.description' => ['sometimes', 'required', 'string', 'max:200'],
            'items.*.unit' => ['required_without:items.*.item_id', 'string', 'max:30'],
            'items.*.discount_percent' => ['sometimes', 'required', 'numeric', 'between:0,100', 'decimal:0,4'],
            'items.*.tax_rate_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tax_rates', 'id')],
            'tax_rate_ids' => ['sometimes', 'array', 'max:30'],
            'tax_rate_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tax_rates', 'id')],
        ];
    }
}
