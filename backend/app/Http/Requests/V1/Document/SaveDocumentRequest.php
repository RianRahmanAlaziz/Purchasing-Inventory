<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Document;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.manage') ?? false;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'document_type' => [$required, 'string', 'max:150'],
            'supplier_id' => [$required, 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'purchase_order_id' => ['sometimes', 'nullable', 'integer', Rule::exists('purchase_orders', 'id')->whereNull('deleted_at')],
            'document_date' => [$required, 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'file' => ['sometimes', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
        ];
    }
}
