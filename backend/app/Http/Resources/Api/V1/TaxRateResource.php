<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TaxRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tax_type_id' => $this->tax_type_id,
            'tax_type' => $this->whenLoaded('taxType', fn (): ?array => $this->taxType === null ? null : [
                'id' => $this->taxType->id,
                'code' => $this->taxType->code,
                'name' => $this->taxType->name,
                'direction' => $this->taxType->direction,
                'is_active' => (bool) $this->taxType->is_active,
            ]),
            'label' => $this->label,
            'rate_percent' => $this->rate_percent,
            'taxable_base_factor' => $this->taxable_base_factor,
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'effective_until' => $this->effective_until?->format('Y-m-d'),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
