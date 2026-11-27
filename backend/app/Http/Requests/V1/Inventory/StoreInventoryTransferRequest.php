<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.transfer.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'from_company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'to_company_id' => ['required', 'integer', 'different:from_company_id', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'transfer_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'integer', 'distinct', Rule::exists('items', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D', 'not_in:0,0.0,0.00,0.000'],
            'items.*.asset_ids' => ['sometimes', 'nullable', 'array', 'max:10000'],
            'items.*.asset_ids.*' => ['required', 'integer', 'distinct', Rule::exists('office_assets', 'id')],
            'status' => ['prohibited'], 'created_by' => ['prohibited'], 'sent_by' => ['prohibited'],
            'received_by' => ['prohibited'], 'sent_at' => ['prohibited'], 'received_at' => ['prohibited'],
        ];
    }
}
