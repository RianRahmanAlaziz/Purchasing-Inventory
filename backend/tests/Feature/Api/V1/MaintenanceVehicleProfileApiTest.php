<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Authorization\Services\ModulePermissionRegistrar;
use App\Domain\Company\Models\Company;
use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Domain\Maintenance\Models\MaintenanceVehicleProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MaintenanceVehicleProfileApiTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT =
    '/api/v1/maintenance/vehicle-profiles';

    private const VIEW = [
        'maintenance.vehicles.view',
        'maintenance.vehicles.view-all-companies',
    ];

    private const MANAGE = [
        'maintenance.vehicles.manage',
        'maintenance.vehicles.manage-all-companies',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());

        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name
        );

        app(ModulePermissionRegistrar::class)->register(
            array_merge(self::VIEW, self::MANAGE)
        );
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        parent::tearDown();
    }

    private function signIn(array $permissions = []): User
    {
        // Role khusus test supaya permission role existing
        // tidak diubah oleh pengujian.
        $role = Role::findOrCreate(
            'test_vehicle_' . Str::lower(Str::random(12)),
            'web'
        );

        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $actor->assignRole($role);

        if ($permissions !== []) {
            $actor->givePermissionTo($permissions);
        }

        Sanctum::actingAs($actor, ['*']);

        return $actor;
    }

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'PT VEHICLE ' . Str::upper(
                Str::random(12)
            ),
        ]);
    }

    private function asset(
        User $actor,
        string $type = 'vehicle',
        ?Company $company = null,
        int $sequence = 1
    ): MaintenanceAsset {
        $company ??= $this->company();

        $asset = new MaintenanceAsset();

        $asset->company_id = $company->id;
        $asset->sequence_number = $sequence;
        $asset->asset_code = 'VEH-' . Str::upper(
            Str::random(14)
        );

        $asset->asset_name = 'Kendaraan Operasional';
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
        string $mode = 'odometer_km'
    ): MaintenanceVehicleProfile {
        $profile = new MaintenanceVehicleProfile();

        $profile->asset_id = $asset->id;
        $profile->fleet_number = 'FL-' . Str::upper(
            Str::random(10)
        );
        $profile->brand = 'Toyota';
        $profile->model = 'Hilux';
        $profile->meter_mode = $mode;
        $profile->created_by = $actor->id;

        $profile->save();

        return $profile->refresh();
    }

    private function payload(int $assetId): array
    {
        return [
            'asset_id' => $assetId,
            'fleet_number' => 'FL-001',
            'registration_number' => 'B 1234 XYZ',
            'brand' => 'Toyota',
            'model' => 'Hilux',
            'manufacture_year' => 2024,
            'meter_mode' => 'odometer_km',
            'reason' => 'Registrasi profil kendaraan operasional.',
        ];
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    public function test_guest_cannot_create_profile(): void
    {
        $this->postJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    public function test_user_without_view_permission_gets_403(): void
    {
        $this->signIn();

        $this->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    public function test_view_requires_global_company_scope(): void
    {
        $this->signIn([
            'maintenance.vehicles.view',
        ]);

        $this->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    public function test_create_requires_manage_and_company_scope(): void
    {
        $actor = $this->signIn([
            'maintenance.vehicles.manage',
        ]);

        $asset = $this->asset($actor);

        $this->postJson(
            self::ENDPOINT,
            $this->payload($asset->id)
        )->assertForbidden();
    }

    public function test_authorized_user_can_create_profile_with_audit(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset($actor);

        $stockBefore = DB::table('stock_movements')->count();

        $this->postJson(
            self::ENDPOINT,
            $this->payload($asset->id)
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.asset_id',
                $asset->id
            )
            ->assertJsonPath(
                'data.meter_mode',
                'odometer_km'
            )
            ->assertJsonPath(
                'data.uses_odometer',
                true
            )
            ->assertJsonPath(
                'data.uses_hourmeter',
                false
            );

        $profile = MaintenanceVehicleProfile::query()
            ->where('asset_id', $asset->id)
            ->firstOrFail();

        $this->assertDatabaseHas(
            'maintenance_vehicle_profile_events',
            [
                'vehicle_profile_id' => $profile->id,
                'changed_by' => $actor->id,
                'action' => 'created',
            ]
        );

        $this->assertSame(
            $stockBefore,
            DB::table('stock_movements')->count()
        );
    }

    public function test_same_asset_cannot_have_two_profiles(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset($actor);
        $payload = $this->payload($asset->id);

        $this->postJson(
            self::ENDPOINT,
            $payload
        )->assertCreated();

        $this->postJson(
            self::ENDPOINT,
            $payload
        )->assertStatus(409);

        $this->assertSame(
            1,
            MaintenanceVehicleProfile::query()
                ->where('asset_id', $asset->id)
                ->count()
        );
    }

    public function test_office_asset_cannot_become_vehicle_profile(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset(
            $actor,
            'office_equipment'
        );

        $this->postJson(
            self::ENDPOINT,
            $this->payload($asset->id)
        )->assertStatus(409);

        $this->assertSame(
            0,
            MaintenanceVehicleProfile::query()
                ->where('asset_id', $asset->id)
                ->count()
        );
    }

    public function test_inactive_asset_cannot_get_profile(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset($actor);

        $asset->is_active = false;
        $asset->save();

        $this->postJson(
            self::ENDPOINT,
            $this->payload($asset->id)
        )->assertStatus(409);
    }

    public function test_heavy_equipment_can_use_hourmeter(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset(
            $actor,
            'heavy_equipment'
        );

        $payload = $this->payload($asset->id);
        $payload['meter_mode'] = 'hourmeter_hours';
        $payload['registration_number'] = null;

        $this->postJson(
            self::ENDPOINT,
            $payload
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.uses_odometer',
                false
            )
            ->assertJsonPath(
                'data.uses_hourmeter',
                true
            );
    }

    public function test_invalid_meter_mode_returns_422(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset($actor);

        $payload = $this->payload($asset->id);
        $payload['meter_mode'] = 'invalid_meter';

        $this->postJson(
            self::ENDPOINT,
            $payload
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meter_mode');
    }

    public function test_authorized_user_can_list_and_filter_profiles(): void
    {
        $actor = $this->signIn(self::VIEW);

        $company = $this->company();

        $vehicle = $this->asset(
            $actor,
            'vehicle',
            $company,
            1
        );

        $equipment = $this->asset(
            $actor,
            'heavy_equipment',
            $company,
            2
        );

        $profile = $this->profile(
            $vehicle,
            $actor
        );

        $this->profile(
            $equipment,
            $actor,
            'hourmeter_hours'
        );

        $this->getJson(
            self::ENDPOINT
                . '?company_id=' . $company->id
                . '&asset_type=vehicle'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $profile->id
            );
    }

    public function test_authorized_user_can_view_detail(): void
    {
        $actor = $this->signIn(self::VIEW);

        $asset = $this->asset($actor);

        $profile = $this->profile(
            $asset,
            $actor
        );

        $this->getJson(
            self::ENDPOINT . '/' . $profile->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.asset.asset_code',
                $asset->asset_code
            )
            ->assertJsonPath(
                'data.brand',
                'Toyota'
            );
    }

    public function test_update_metadata_creates_audit(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset($actor);
        $profile = $this->profile($asset, $actor);

        $this->patchJson(
            self::ENDPOINT . '/' . $profile->id,
            [
                'brand' => 'Isuzu',
                'meter_mode' => 'both',
                'reason' => 'Koreksi identitas dan jenis meter.',
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.brand', 'Isuzu')
            ->assertJsonPath('data.meter_mode', 'both');

        $this->assertDatabaseHas(
            'maintenance_vehicle_profile_events',
            [
                'vehicle_profile_id' => $profile->id,
                'changed_by' => $actor->id,
                'action' => 'updated',
            ]
        );

        $this->assertSame(
            $asset->id,
            $profile->refresh()->asset_id
        );
    }

    public function test_update_cannot_change_asset_ownership(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $asset = $this->asset($actor);
        $profile = $this->profile($asset, $actor);

        $this->patchJson(
            self::ENDPOINT . '/' . $profile->id,
            [
                'asset_id' => 999,
                'company_id' => 999,
                'reason' => 'Mencoba mengganti kepemilikan aset.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'asset_id',
                'company_id',
            ]);

        $this->assertSame(
            $asset->id,
            $profile->refresh()->asset_id
        );
    }

    public function test_update_requires_reason(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $profile = $this->profile(
            $this->asset($actor),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $profile->id,
            [
                'brand' => 'Isuzu',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_update_requires_metadata(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $profile = $this->profile(
            $this->asset($actor),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $profile->id,
            [
                'reason' => 'Hanya alasan tanpa perubahan profil.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata');
    }

    public function test_same_metadata_does_not_create_audit(): void
    {
        $actor = $this->signIn(self::MANAGE);

        $profile = $this->profile(
            $this->asset($actor),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $profile->id,
            [
                'brand' => 'Toyota',
                'reason' => 'Verifikasi ulang identitas kendaraan.',
            ]
        )->assertOk();

        $this->assertSame(
            0,
            DB::table('maintenance_vehicle_profile_events')
                ->where('vehicle_profile_id', $profile->id)
                ->count()
        );
    }

    public function test_delete_is_not_exposed(): void
    {
        $actor = $this->signIn(
            array_merge(self::VIEW, self::MANAGE)
        );

        $profile = $this->profile(
            $this->asset($actor),
            $actor
        );

        $this->deleteJson(
            self::ENDPOINT . '/' . $profile->id
        )->assertStatus(405);
    }
}
