<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MaintenanceAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'asset_code' => $this->asset_code,
            'sequence_number' => $this->sequence_number,
            'asset_name' => $this->asset_name,
            'asset_type' => $this->asset_type,

            'serial_number' => $this->serial_number,
            'location' => $this->location,
            'notes' => $this->notes,

            'is_active' => (bool) $this->is_active,

            'registration_source' => $this->registration_source,

            'source_goods_receipt_item_id' =>
            $this->source_goods_receipt_item_id,

            'receipt_unit_ordinal' =>
            $this->receipt_unit_ordinal,

            'company_id' => $this->company_id,
            'item_id' => $this->item_id,
            'registered_by' => $this->registered_by,

            'company' => $this->whenLoaded(
                'company',
                fn(): ?array => $this->company === null
                    ? null
                    : [
                        'id' => $this->company->id,
                        'name' => $this->company->name,
                    ]
            ),

            'item' => $this->whenLoaded(
                'item',
                fn(): ?array => $this->item === null
                    ? null
                    : [
                        'id' => $this->item->id,
                        'sku' => $this->item->sku,
                        'name' => $this->item->name,
                    ]
            ),

            'registrar' => $this->whenLoaded(
                'registrar',
                fn(): ?array => $this->registrar === null
                    ? null
                    : [
                        'id' => $this->registrar->id,
                        'name' => $this->registrar->name,
                    ]
            ),

            'registered_at' =>  $this->registered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' =>  $this->updated_at?->toIso8601String(),
        ];
    }
}
