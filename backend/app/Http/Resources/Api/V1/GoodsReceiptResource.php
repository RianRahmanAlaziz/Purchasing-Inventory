<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grn_number' => $this->grn_number,
            'purchase_order_id' => $this->purchase_order_id,
            'po_number' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder?->po_number),
            'received_date' => $this->received_date?->format('Y-m-d'),
            'status' => $this->status,
            'created_by' => $this->created_by,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(
                static function ($line): array {
                    $poLine = $line->relationLoaded('purchaseOrderItem')
                        ? $line->purchaseOrderItem : null;
                    $movement = $line->relationLoaded('movement') ? $line->movement : null;

                    return [
                        'id' => $line->id,
                        'purchase_order_item_id' => $line->purchase_order_item_id,
                        'item_id' => $poLine?->item_id,
                        'sku' => $poLine?->item_sku_snapshot,
                        'name' => $poLine?->item_name_snapshot,
                        'unit' => $poLine?->unit_snapshot,
                        'qty_received' => $line->qty_received,
                        'qty_damaged' => $line->qty_damaged,
                        'notes' => $line->notes,
                        'stock_movement_id' => $movement?->id,
                    ];
                }
            )->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
