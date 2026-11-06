<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $supplier = $this->input('supplier_id', $this->route('invoice')?->supplier_id);

        return [
            'invoice_number' => [$required, 'string', 'max:100', Rule::unique('invoices')->where('supplier_id', $supplier)->ignore($this->route('invoice'))],
            'supplier_id' => [$required, 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'purchase_order_id' => ['sometimes', 'nullable', 'integer', Rule::exists('purchase_orders', 'id')->whereNull('deleted_at')],
            'invoice_date' => [$required, 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'total_amount' => [$required, 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\\.[0-9]{1,2})?$/D'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
