<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SavePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.manage') ?? false;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'invoice_id' => [$required, 'integer', Rule::exists('invoices', 'id')->whereNull('deleted_at')],
            'payment_date' => [$required, 'date_format:Y-m-d'],
            'amount' => [$required, 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\\.[0-9]{1,2})?$/D'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
