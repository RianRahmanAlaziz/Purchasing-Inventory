<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Receiving;

use App\Domain\Inventory\Support\StockDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('grn.create') ?? false;
    }

    /** Validasi per-item di level HTTP; Action tetap memeriksa ulang saat confirm. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $items = $this->input('items', []);
            if (! is_array($items)) {
                return;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item)
                    || ! is_string($item['qty_received'] ?? null)
                    || (isset($item['qty_damaged']) && ! is_string($item['qty_damaged']))) {
                    continue;
                }
                try {
                    $received = StockDecimal::parse($item['qty_received']);
                    $damaged = StockDecimal::parse($item['qty_damaged'] ?? '0.000');
                    if ($damaged > $received) {
                        $validator->errors()->add(
                            "items.{$i}.qty_damaged",
                            'Jumlah barang rusak tidak boleh melebihi jumlah diterima.'
                        );
                    }
                } catch (\InvalidArgumentException) {
                    // Sudah ditolak oleh aturan regex/required di rules().
                }
            }
        }];
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'confirmed_at' => ['prohibited'],
            'confirmed_by' => ['prohibited'],
            'grn_number' => ['prohibited'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_id' => [
                'required', 'integer', 'distinct', Rule::exists('purchase_order_items', 'id'),
            ],
            // Kuantitas JSON diwajibkan sebagai string desimal, bukan float.
            'items.*.qty_received' => [
                'required', 'string',
                'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D',
                'not_in:0,0.0,0.00,0.000',
            ],
            'items.*.qty_damaged' => [
                'sometimes', 'required', 'string',
                'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D',
            ],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
