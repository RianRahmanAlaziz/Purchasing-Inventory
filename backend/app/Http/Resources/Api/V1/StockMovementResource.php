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
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn (): ?array => $this->company === null ? null : [
                'id' => $this->company->id, 'name' => $this->company->name,
            ]),
            'from_company' => $this->whenLoaded('fromCompany', fn (): ?array => $this->fromCompany === null ? null : [
                'id' => $this->fromCompany->id, 'name' => $this->fromCompany->name,
            ]),
            'to_company' => $this->whenLoaded('toCompany', fn (): ?array => $this->toCompany === null ? null : [
                'id' => $this->toCompany->id, 'name' => $this->toCompany->name,
            ]),
            'transfer_item_id' => $this->transfer_item_id,
            'asset_id' => $this->asset_id,
            'operation_key' => $this->operation_key,
            'transfer' => $this->whenLoaded('transferItem', fn (): ?array => $this->transferItem?->transfer === null ? null : [
                'id' => $this->transferItem->transfer->id, 'transfer_number' => $this->transferItem->transfer->transfer_number,
            ]),
            'actor_name' => $this->whenLoaded('actor', fn (): ?string => $this->actor?->name),
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
