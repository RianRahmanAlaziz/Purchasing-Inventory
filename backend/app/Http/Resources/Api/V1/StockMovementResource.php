<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn (): array => [
                'sku' => $this->item->sku,
                'name' => $this->item->name,
                'unit' => $this->item->unit,
            ]),
            'movement_type' => $this->movement_type,
            'quantity_change' => $this->quantity_change,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'goods_receipt_item_id' => $this->goods_receipt_item_id,
            'distribution_item_id' => $this->distribution_item_id,
            'stock_adjustment_id' => $this->stock_adjustment_id,
            'created_by' => $this->created_by,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
