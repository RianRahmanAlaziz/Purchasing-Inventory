<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStockAdjustmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Kunci hanya boleh berasal dari header, bukan payload JSON.
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjust') ?? false;
    }

    public function rules(): array
    {
        $quantityPattern = 'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D';

        return [
            'expected_quantity' => ['required', 'string', $quantityPattern],
            'target_quantity' => ['required', 'string', $quantityPattern],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'item_id' => ['prohibited'],
            'quantity_difference' => ['prohibited'],
            'stock_before' => ['prohibited'],
            'stock_after' => ['prohibited'],
            'adjusted_by' => ['prohibited'],
        ];
    }
}
