<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MaintenanceVehicleProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,

            'fleet_number' => $this->fleet_number,
            'registration_number' => $this->registration_number,

            'brand' => $this->brand,
            'model' => $this->model,
            'manufacture_year' => $this->manufacture_year,

            'chassis_number' => $this->chassis_number,
            'engine_number' => $this->engine_number,

            'meter_mode' => $this->meter_mode,

            'uses_odometer' => $this->usesOdometer(),
            'uses_hourmeter' => $this->usesHourmeter(),

            'notes' => $this->notes,

            'asset' => $this->whenLoaded(
                'asset',
                function (): ?array {
                    $asset = $this->asset;

                    if ($asset === null) {
                        return null;
                    }

                    $company = $asset->company;

                    return [
                        'id' => $asset->id,
                        'asset_code' => $asset->asset_code,
                        'asset_name' => $asset->asset_name,
                        'asset_type' => $asset->asset_type,
                        'is_active' => (bool) $asset->is_active,

                        'company' => $company === null
                            ? null
                            : [
                                'id' => $company->id,
                                'name' => $company->name,
                            ],
                    ];
                }
            ),

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,

            'creator' => $this->whenLoaded(
                'creator',
                fn(): ?array => $this->creator === null
                    ? null
                    : [
                        'id' => $this->creator->id,
                        'name' => $this->creator->name,
                    ]
            ),

            'updater' => $this->whenLoaded(
                'updater',
                fn(): ?array => $this->updater === null
                    ? null
                    : [
                        'id' => $this->updater->id,
                        'name' => $this->updater->name,
                    ]
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
