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
            'stock_quantity' => $this->stock_quantity,
            'minimum_stock' => $this->minimum_stock,
            'last_purchase_price' => $this->last_purchase_price,
            'is_low_stock' => (bool) $this->is_low_stock,
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
