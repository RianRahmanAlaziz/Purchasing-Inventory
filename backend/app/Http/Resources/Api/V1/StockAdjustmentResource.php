<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'adjusted_by' => $this->adjusted_by,
            'stock_before' => $this->stock_before,
            'stock_after' => $this->stock_after,
            'quantity_difference' => $this->quantity_difference,
            'reason' => $this->reason,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'movement_id' => $this->whenLoaded('movement', fn () => $this->movement?->id),
        ];
    }
}
