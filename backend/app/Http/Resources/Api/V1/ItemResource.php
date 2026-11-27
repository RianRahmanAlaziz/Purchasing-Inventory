<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            // DECIMAL dikirim sebagai string, menjaga presisi angka.
            'stock_quantity' => $this->company_stock_quantity ?? $this->stock_quantity,
            'total_stock_quantity' => $this->stock_quantity,
            'item_type' => $this->item_type,
            'unallocated_quantity' => $this->whenLoaded('balances', fn () => (string) ($this->balances->firstWhere('company_id', null)?->quantity ?? '0.000')),
            'company_balances' => $this->whenLoaded('balances', fn () => $this->balances->map(fn ($b) => ['company_id' => $b->company_id, 'company_name' => $b->company?->name, 'quantity' => $b->quantity])->values()->all()),
            'minimum_stock' => $this->minimum_stock,
            'maximum_stock' => $this->maximum_stock,
            'reference_price' => $this->reference_price,
            'last_purchase_price' => $this->last_purchase_price,
            'is_low_stock' => (bool) $this->is_low_stock,
            'stock_level' => $this->stock_level,
            'is_active' => (bool) $this->is_active,
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category === null ? null : [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
                'is_active' => (bool) $this->category->is_active,
            ]
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
