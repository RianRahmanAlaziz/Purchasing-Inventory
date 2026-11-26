<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Company\Models\Company;
use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Domain\Maintenance\Models\MaintenanceVehicleProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaintenanceVehicleProfileSchemaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name
        );
    }

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'PT VEH ' . Str::upper(Str::random(12)),
        ]);
    }

    private function asset(
        Company $company,
        User $actor,
        string $type = 'vehicle',
        int $sequence = 1
    ): MaintenanceAsset {
        $asset = new MaintenanceAsset();

        $asset->company_id = $company->id;
        $asset->sequence_number = $sequence;
        $asset->asset_code = 'VEH-' . Str::upper(Str::random(14));

        $asset->asset_name = 'Unit Kendaraan Test';
        $asset->asset_type = $type;
        $asset->registration_source = 'legacy_manual';

        $asset->registered_by = $actor->id;
        $asset->registered_at = now();
        $asset->is_active = true;

        $asset->save();

        return $asset->refresh();
    }

    private function profile(
        MaintenanceAsset $asset,
        User $actor,
        string $mode = MaintenanceVehicleProfile::METER_ODOMETER_KM
    ): MaintenanceVehicleProfile {
        $profile = new MaintenanceVehicleProfile();

        $profile->asset_id = $asset->id;
        $profile->fleet_number = 'FLEET-' . Str::upper(
            Str::random(8)
        );
        $profile->brand = 'Test Brand';
        $profile->model = 'Test Model';
        $profile->manufacture_year = 2024;
        $profile->meter_mode = $mode;
        $profile->created_by = $actor->id;

        $profile->save();

        return $profile->refresh();
    }

    public function test_vehicle_profile_schema_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('maintenance_vehicle_profiles')
        );

        $this->assertTrue(
            Schema::hasColumns(
                'maintenance_vehicle_profiles',
                [
                    'id',
                    'asset_id',
                    'fleet_number',
                    'registration_number',
                    'brand',
                    'model',
                    'manufacture_year',
                    'chassis_number',
                    'engine_number',
                    'meter_mode',
                    'created_by',
                    'updated_by',
                    'created_at',
                    'updated_at',
                ]
            )
        );
    }

    public function test_vehicle_profile_belongs_to_asset_and_company(): void
    {
        $company = $this->company();
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $asset = $this->asset($company, $actor);
        $profile = $this->profile($asset, $actor);

        $this->assertSame(
            $asset->id,
            $profile->asset->id
        );

        $this->assertSame(
            $company->id,
            $profile->asset->company->id
        );

        $this->assertSame(
            $profile->id,
            $asset->fresh()->vehicleProfile->id
        );

        $this->assertSame(
            $actor->id,
            $profile->creator->id
        );
    }

    public function test_heavy_equipment_can_use_hourmeter_without_plate(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $asset = $this->asset(
            $this->company(),
            $actor,
            'heavy_equipment'
        );

        $profile = $this->profile(
            $asset,
            $actor,
            MaintenanceVehicleProfile::METER_HOURMETER_HOURS
        );

        $this->assertNull($profile->registration_number);
        $this->assertTrue($profile->usesHourmeter());
        $this->assertFalse($profile->usesOdometer());
        $this->assertSame(
            'heavy_equipment',
            $profile->asset->asset_type
        );
    }

    public function test_profile_supports_km_hourmeter_and_both(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $company = $this->company();

        $km = $this->profile(
            $this->asset($company, $actor, 'vehicle', 1),
            $actor,
            MaintenanceVehicleProfile::METER_ODOMETER_KM
        );

        $hours = $this->profile(
            $this->asset($company, $actor, 'heavy_equipment', 2),
            $actor,
            MaintenanceVehicleProfile::METER_HOURMETER_HOURS
        );

        $both = $this->profile(
            $this->asset($company, $actor, 'heavy_equipment', 3),
            $actor,
            MaintenanceVehicleProfile::METER_BOTH
        );

        $this->assertTrue($km->usesOdometer());
        $this->assertFalse($km->usesHourmeter());

        $this->assertTrue($hours->usesHourmeter());
        $this->assertFalse($hours->usesOdometer());

        $this->assertTrue($both->usesOdometer());
        $this->assertTrue($both->usesHourmeter());
    }

    public function test_same_asset_cannot_have_two_profiles(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->profile($asset, $actor);

        $this->expectException(QueryException::class);

        $this->profile(
            $asset,
            $actor,
            MaintenanceVehicleProfile::METER_BOTH
        );
    }

    public function test_profile_requires_existing_asset(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $profile = new MaintenanceVehicleProfile();

        $profile->asset_id = 9999999999;
        $profile->meter_mode =
            MaintenanceVehicleProfile::METER_ODOMETER_KM;
        $profile->created_by = $actor->id;

        $this->expectException(QueryException::class);

        $profile->save();
    }

    public function test_asset_with_profile_cannot_be_hard_deleted(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->profile($asset, $actor);

        $this->expectException(QueryException::class);

        DB::table('maintenance_assets')
            ->where('id', $asset->id)
            ->delete();
    }

    public function test_creating_profile_does_not_change_inventory(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $itemCountBefore = DB::table('items')->count();

        $movementCountBefore = DB::table(
            'stock_movements'
        )->count();

        $this->profile($asset, $actor);

        $this->assertSame(
            $itemCountBefore,
            DB::table('items')->count()
        );

        $this->assertSame(
            $movementCountBefore,
            DB::table('stock_movements')->count()
        );
    }
}
