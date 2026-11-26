<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Authorization\Services\ModulePermissionRegistrar;
use App\Domain\Company\Models\Company;
use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MaintenanceAssetApiTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = '/api/v1/maintenance/assets';

    private const VIEW_PERMISSIONS = [
        'maintenance.assets.view',
        'maintenance.assets.view-all-companies',
    ];

    private const MANAGE_PERMISSIONS = [
        'maintenance.assets.manage',
        'maintenance.assets.manage-all-companies',
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

        app(ModulePermissionRegistrar::class)
            ->register(array_merge(
                self::VIEW_PERMISSIONS,
                self::MANAGE_PERMISSIONS
            ));

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        parent::tearDown();
    }

    private function signIn(array $permissions = []): User
    {
        $role = Role::findOrCreate('hr', 'web');

        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'PT ' . Str::upper(Str::random(12)),
        ]);
    }

    private function asset(
        Company $company,
        User $actor
    ): MaintenanceAsset {
        $asset = new MaintenanceAsset();

        $asset->company_id = $company->id;
        $asset->sequence_number = 1;

        $asset->asset_code = 'AST-' . Str::upper(
            Str::random(14)
        );

        $asset->asset_name = 'Laptop Operasional';
        $asset->asset_type = 'office_equipment';

        $asset->registration_source = 'legacy_manual';
        $asset->registered_by = $actor->id;
        $asset->registered_at = now();

        $asset->save();

        return $asset->refresh();
    }

    public function test_guest_cannot_read_assets(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    public function test_user_without_view_permission_gets_403(): void
    {
        $this->signIn();

        $this->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    public function test_view_without_global_scope_is_forbidden(): void
    {
        $this->signIn([
            'maintenance.assets.view',
        ]);

        $this->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    public function test_authorized_user_can_list_and_filter_assets(): void
    {
        $actor = $this->signIn(self::VIEW_PERMISSIONS);

        $companyA = $this->company();
        $companyB = $this->company();

        $assetA = $this->asset($companyA, $actor);
        $this->asset($companyB, $actor);

        $this->getJson(
            self::ENDPOINT . '?company_id=' . $companyA->id
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $assetA->id
            );
    }

    public function test_authorized_user_can_read_asset_detail(): void
    {
        $actor = $this->signIn(self::VIEW_PERMISSIONS);

        $company = $this->company();
        $asset = $this->asset($company, $actor);

        $this->getJson(self::ENDPOINT . '/' . $asset->id)
            ->assertOk()
            ->assertJsonPath(
                'data.asset_code',
                $asset->asset_code
            )
            ->assertJsonPath(
                'data.company.id',
                $company->id
            );
    }

    public function test_manage_without_global_scope_is_forbidden(): void
    {
        $actor = $this->signIn([
            'maintenance.assets.manage',
        ]);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            [
                'asset_name' => 'Laptop Baru',
                'reason' => 'Perbaikan nama aset lama.',
            ]
        )->assertForbidden();
    }

    public function test_authorized_user_can_update_metadata_with_audit(): void
    {
        $actor = $this->signIn(self::MANAGE_PERMISSIONS);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            [
                'asset_name' => 'Laptop Finance',
                'location' => 'Ruang Finance',
                'reason' => 'Penyesuaian identitas aset perusahaan.',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.asset_name',
                'Laptop Finance'
            )
            ->assertJsonPath(
                'data.location',
                'Ruang Finance'
            );

        $this->assertDatabaseHas(
            'maintenance_asset_change_events',
            [
                'asset_id' => $asset->id,
                'changed_by' => $actor->id,
            ]
        );

        $this->assertSame(
            $asset->company_id,
            $asset->refresh()->company_id
        );
    }

    public function test_immutable_fields_are_rejected(): void
    {
        $actor = $this->signIn(self::MANAGE_PERMISSIONS);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            [
                'company_id' => 999,
                'asset_code' => 'FAKE-001',
                'is_active' => false,
                'reason' => 'Mencoba mengubah identitas aset.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_id',
                'asset_code',
                'is_active',
            ]);
    }

    public function test_update_requires_reason(): void
    {
        $actor = $this->signIn(self::MANAGE_PERMISSIONS);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            [
                'asset_name' => 'Nama Baru',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_update_requires_at_least_one_metadata_field(): void
    {
        $actor = $this->signIn(self::MANAGE_PERMISSIONS);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            [
                'reason' => 'Hanya alasan tanpa perubahan data.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('asset_name');
    }

    public function test_same_value_does_not_duplicate_audit(): void
    {
        $actor = $this->signIn(self::MANAGE_PERMISSIONS);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $payload = [
            'asset_name' => 'Laptop Finance',
            'reason' => 'Penyesuaian identitas aset perusahaan.',
        ];

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            $payload
        )->assertOk();

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            $payload
        )->assertOk();

        $this->assertSame(
            1,
            DB::table('maintenance_asset_change_events')
                ->where('asset_id', $asset->id)
                ->count()
        );
    }

    public function test_metadata_update_does_not_mutate_stock(): void
    {
        $actor = $this->signIn(self::MANAGE_PERMISSIONS);

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $beforeMovements = DB::table(
            'stock_movements'
        )->count();

        $this->patchJson(
            self::ENDPOINT . '/' . $asset->id,
            [
                'location' => 'Gudang Utama',
                'reason' => 'Koreksi informasi lokasi barang.',
            ]
        )->assertOk();

        $this->assertSame(
            $beforeMovements,
            DB::table('stock_movements')->count()
        );
    }

    public function test_create_and_delete_are_not_exposed(): void
    {
        $actor = $this->signIn(array_merge(
            self::VIEW_PERMISSIONS,
            self::MANAGE_PERMISSIONS
        ));

        $asset = $this->asset(
            $this->company(),
            $actor
        );

        $this->postJson(self::ENDPOINT, [
            'asset_name' => 'Aset Tidak Sah',
        ])->assertStatus(405);

        $this->deleteJson(
            self::ENDPOINT . '/' . $asset->id
        )->assertStatus(405);
    }
}
