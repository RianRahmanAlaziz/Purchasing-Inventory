<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateMaintenanceAssetMetadata
{
    private const EDITABLE = [
        'asset_name',
        'serial_number',
        'location',
        'notes',
    ];

    public function execute(
        int $assetId,
        array $data,
        User $actor
    ): MaintenanceAsset {
        return DB::transaction(function () use (
            $assetId,
            $data,
            $actor
        ): MaintenanceAsset {
            $asset = MaintenanceAsset::query()
                ->lockForUpdate()
                ->findOrFail($assetId);

            // Periksa lagi izin setelah record dikunci.
            Gate::forUser($actor)->authorize(
                'update',
                $asset
            );

            $changes = Arr::only(
                $data,
                self::EDITABLE
            );

            $before = [];
            $after = [];

            foreach ($changes as $field => $value) {
                $oldValue = $asset->getAttribute($field);

                if ($oldValue === $value) {
                    continue;
                }

                $before[$field] = $oldValue;
                $after[$field] = $value;

                // Guarded model tetap aman karena Action
                // menetapkan atribut secara eksplisit.
                $asset->setAttribute($field, $value);
            }

            // Tidak membuat audit palsu jika data sama.
            if ($after === []) {
                return $asset->load([
                    'company',
                    'item',
                    'registrar',
                ]);
            }

            $asset->save();

            DB::table('maintenance_asset_change_events')
                ->insert([
                    'asset_id' => $asset->id,
                    'changed_by' => $actor->id,

                    'before_data' => json_encode(
                        $before,
                        JSON_THROW_ON_ERROR
                    ),

                    'after_data' => json_encode(
                        $after,
                        JSON_THROW_ON_ERROR
                    ),

                    'reason' => $data['reason'],
                    'created_at' => now(),
                ]);

            return $asset->refresh()->load([
                'company',
                'item',
                'registrar',
            ]);
        }, attempts: 3);
    }
}
