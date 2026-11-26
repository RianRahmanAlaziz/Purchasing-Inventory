<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Domain\Maintenance\Models\MaintenanceVehicleProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class SaveMaintenanceVehicleProfile
{
    private const EDITABLE = [
        'fleet_number',
        'registration_number',
        'brand',
        'model',
        'manufacture_year',
        'chassis_number',
        'engine_number',
        'meter_mode',
        'notes',
    ];

    public function create(
        array $data,
        User $actor
    ): MaintenanceVehicleProfile {
        Gate::forUser($actor)->authorize(
            'create',
            MaintenanceVehicleProfile::class
        );

        try {
            return DB::transaction(function () use (
                $data,
                $actor
            ): MaintenanceVehicleProfile {
                $asset = MaintenanceAsset::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $data['asset_id']);

                $this->assertEligibleAsset($asset);

                if ($asset->vehicleProfile()->exists()) {
                    throw new VehicleProfileConflict(
                        'Aset sudah memiliki profil kendaraan.'
                    );
                }

                $profile = new MaintenanceVehicleProfile();

                $profile->asset_id = $asset->id;

                $changes = Arr::only(
                    $data,
                    self::EDITABLE
                );

                $this->assertValidMeterMode(
                    $changes['meter_mode'] ?? null
                );

                foreach ($changes as $field => $value) {
                    $profile->setAttribute($field, $value);
                }

                $profile->created_by = $actor->id;
                $profile->save();

                $this->writeAudit(
                    $profile,
                    $actor,
                    'created',
                    [],
                    array_merge(
                        [
                            'asset_id' => (int) $asset->id,
                            'created_by' => (int) $actor->id,
                        ],
                        $changes
                    ),
                    (string) $data['reason']
                );

                return $this->loaded($profile);
            }, attempts: 3);
        } catch (QueryException $exception) {
            $this->rejectDuplicate($exception);

            throw $exception;
        }
    }

    public function update(
        int $profileId,
        array $data,
        User $actor
    ): MaintenanceVehicleProfile {
        try {
            return DB::transaction(function () use (
                $profileId,
                $data,
                $actor
            ): MaintenanceVehicleProfile {
                // Pembacaan awal menentukan parent ID.
                // Lock tetap dilakukan dari Asset -> Profile.
                $assetId = DB::table(
                    'maintenance_vehicle_profiles'
                )
                    ->where('id', $profileId)
                    ->value('asset_id');

                if ($assetId === null) {
                    abort(404);
                }

                $asset = MaintenanceAsset::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $assetId);

                $profile = MaintenanceVehicleProfile::query()
                    ->lockForUpdate()
                    ->findOrFail($profileId);

                if ((int) $profile->asset_id !== (int) $asset->id) {
                    throw new VehicleProfileConflict(
                        'Relasi profil dan aset berubah.'
                    );
                }

                Gate::forUser($actor)->authorize(
                    'update',
                    $profile
                );

                $this->assertEligibleAsset($asset);

                $changes = Arr::only(
                    $data,
                    self::EDITABLE
                );

                if (array_key_exists('meter_mode', $changes)) {
                    $this->assertValidMeterMode(
                        $changes['meter_mode']
                    );
                }

                $before = [];
                $after = [];

                foreach ($changes as $field => $value) {
                    $previous = $profile->getAttribute($field);

                    if ($previous === $value) {
                        continue;
                    }

                    $before[$field] = $previous;
                    $after[$field] = $value;

                    $profile->setAttribute($field, $value);
                }

                // Data sama: tidak mengubah updated_at,
                // aktor terakhir, maupun audit.
                if ($after === []) {
                    return $this->loaded($profile);
                }

                $before['updated_by'] = $profile->updated_by;
                $after['updated_by'] = (int) $actor->id;

                $profile->updated_by = $actor->id;
                $profile->save();

                $this->writeAudit(
                    $profile,
                    $actor,
                    'updated',
                    $before,
                    $after,
                    (string) $data['reason']
                );

                return $this->loaded($profile->refresh());
            }, attempts: 3);
        } catch (QueryException $exception) {
            $this->rejectDuplicate($exception);

            throw $exception;
        }
    }

    private function assertEligibleAsset(
        MaintenanceAsset $asset
    ): void {
        if (! in_array(
            $asset->asset_type,
            ['vehicle', 'heavy_equipment'],
            true
        )) {
            throw new VehicleProfileConflict(
                'Profil hanya boleh dibuat untuk kendaraan atau alat berat.'
            );
        }

        if (! $asset->is_active) {
            throw new VehicleProfileConflict(
                'Aset tidak aktif.'
            );
        }

        // Soft-deleted Company tidak boleh dipakai
        // untuk pembuatan/perubahan operasional.
        if (! Company::query()
            ->whereKey($asset->company_id)
            ->exists()) {
            throw new VehicleProfileConflict(
                'PT pemilik aset tidak aktif dalam master perusahaan.'
            );
        }
    }

    private function assertValidMeterMode(
        mixed $mode
    ): void {
        if (! is_string($mode) || ! in_array(
            $mode,
            MaintenanceVehicleProfile::METER_MODES,
            true
        )) {
            throw new VehicleProfileConflict(
                'Mode meter kendaraan tidak valid.'
            );
        }
    }

    private function writeAudit(
        MaintenanceVehicleProfile $profile,
        User $actor,
        string $action,
        array $before,
        array $after,
        string $reason
    ): void {
        DB::table('maintenance_vehicle_profile_events')
            ->insert([
                'vehicle_profile_id' => $profile->id,
                'changed_by' => $actor->id,
                'action' => $action,

                'before_data' => json_encode(
                    (object) $before,
                    JSON_THROW_ON_ERROR
                ),

                'after_data' => json_encode(
                    (object) $after,
                    JSON_THROW_ON_ERROR
                ),

                'reason' => $reason,
                'created_at' => now(),
            ]);
    }

    private function loaded(
        MaintenanceVehicleProfile $profile
    ): MaintenanceVehicleProfile {
        return $profile->load([
            'asset.company',
            'creator:id,name',
            'updater:id,name',
        ]);
    }

    private function rejectDuplicate(
        QueryException $exception
    ): void {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new VehicleProfileConflict(
                'Profil kendaraan sudah terdaftar atau terjadi konflik data.',
                previous: $exception
            );
        }
    }
}
