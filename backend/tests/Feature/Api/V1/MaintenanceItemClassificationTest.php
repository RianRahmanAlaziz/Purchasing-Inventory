<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Authorization\Services\ModulePermissionRegistrar;
use App\Domain\Category\Models\Category;
use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MaintenanceItemClassificationTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSION = 'maintenance.asset-items.classify';
    private const ENDPOINT = '/api/v1/maintenance/item-classifications/';

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
            ->register([self::PERMISSION]);

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        parent::tearDown();
    }

    private function signIn(
        string $roleName,
        bool $canClassify = false,
        array $otherPermissions = [],
    ): User {
        $role = Role::findOrCreate($roleName, 'web');

        $permissions = $otherPermissions;

        if ($canClassify) {
            $permissions[] = self::PERMISSION;
        }

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function createItem(): Item
    {
        $category = Category::query()->create([
            'code' => 'MAINT-TEST',
            'name' => 'Peralatan Maintenance',
            'is_active' => true,
        ]);

        return Item::query()->create([
            'category_id' => $category->id,
            'sku' => 'LAPTOP-MAINT-001',
            'name' => 'Laptop Maintenance',
            'unit' => 'pcs',
            'minimum_stock' => '0.000',
            'is_active' => true,
        ]);
    }

    private function payload(bool $value = true): array
    {
        return [
            'is_asset_trackable' => $value,
            'reason' => 'Barang termasuk aset perusahaan.',
        ];
    }

    public function test_item_defaults_to_non_asset(): void
    {
        $item = $this->createItem();

        $this->assertFalse(
            (bool) $item->refresh()->is_asset_trackable
        );
    }

    public function test_guest_gets_401(): void
    {
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )->assertUnauthorized();
    }

    public function test_super_admin_with_permission_can_classify(): void
    {
        $user = $this->signIn('super_admin', true);
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )
            ->assertOk()
            ->assertJsonPath('data.is_asset_trackable', true);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'is_asset_trackable' => 1,
        ]);

        $this->assertDatabaseHas(
            'maintenance_item_classification_events',
            [
                'item_id' => $item->id,
                'changed_by' => $user->id,
                'before_value' => 0,
                'after_value' => 1,
            ]
        );

        $this->assertDatabaseCount(
            'maintenance_item_classification_events',
            1
        );
    }

    public function test_hr_with_permission_can_classify(): void
    {
        $this->signIn('hr', true);
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )
            ->assertOk()
            ->assertJsonPath('data.is_asset_trackable', true);
    }

    public function test_super_admin_without_permission_gets_403(): void
    {
        $this->signIn('super_admin');
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )->assertForbidden();
    }

    public function test_hr_without_permission_gets_403(): void
    {
        $this->signIn('hr', false, ['items.manage']);
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )->assertForbidden();
    }

    public function test_staff_hr_with_classify_permission_still_gets_403(): void
    {
        $this->signIn('staff_hr', true);
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )->assertForbidden();
    }

    public function test_general_manager_with_permission_gets_403(): void
    {
        $this->signIn('general_manager', true);
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )->assertForbidden();
    }

    public function test_reason_is_required(): void
    {
        $this->signIn('hr', true);
        $item = $this->createItem();

        $this->patchJson(self::ENDPOINT . $item->id, [
            'is_asset_trackable' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_same_value_does_not_duplicate_audit(): void
    {
        $this->signIn('hr', true);
        $item = $this->createItem();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )->assertOk();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )->assertOk();

        $this->assertDatabaseCount(
            'maintenance_item_classification_events',
            1
        );
    }

    public function test_item_crud_cannot_bypass_classification(): void
    {
        $this->signIn(
            'super_admin',
            true,
            ['items.manage', 'items.view']
        );

        $item = $this->createItem();

        $this->patchJson('/api/v1/items/' . $item->id, [
            'is_asset_trackable' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_asset_trackable');

        $this->assertFalse(
            (bool) $item->refresh()->is_asset_trackable
        );
    }

    public function test_classification_does_not_change_stock(): void
    {
        $this->signIn('hr', true);

        $item = $this->createItem();

        // Ambil nilai default yang sudah disimpan di MySQL.
        $item->refresh();

        $before = (string) $item->stock_quantity;

        $movementCount = DB::table('stock_movements')->count();

        $this->patchJson(
            self::ENDPOINT . $item->id,
            $this->payload()
        )
            ->assertOk()
            ->assertJsonPath('data.is_asset_trackable', true);

        $item->refresh();

        // Klasifikasi tidak boleh mengubah stok barang.
        $this->assertSame(
            $before,
            (string) $item->stock_quantity
        );

        // Klasifikasi tidak boleh membuat stock movement.
        $this->assertSame(
            $movementCount,
            DB::table('stock_movements')->count()
        );
    }
}
