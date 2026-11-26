<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RoleAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_cannot_read_roles(): void
    {
        $this->getJson('/api/v1/authorization/roles')
            ->assertUnauthorized();
    }

    public function test_staff_hr_cannot_manage_roles(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole('staff_hr');

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/authorization/roles')
            ->assertForbidden();
    }

    public function test_staff_hr_can_confirm_receipts_and_process_distributions_without_hr_role(): void
    {
        $user = User::factory()->create(['is_active' => true])->assignRole('staff_hr');
        Sanctum::actingAs($user, ['*']);

        $this->assertFalse($user->hasRole('hr'));
        $this->assertTrue($user->can('grn.confirm'));
        $this->assertTrue($user->can('distribution.create'));
        $this->assertTrue($user->can('distribution.send'));
        $this->assertFalse($user->can('distribution.approve'));
        $this->postJson('/api/v1/goods-receipts/999999999/confirm')->assertNotFound();
        $this->postJson('/api/v1/distributions', [])->assertUnprocessable();
    }

    public function test_super_admin_can_read_roles(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole('super_admin');

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/authorization/roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'name' => 'super_admin',
            ]);
    }

    public function test_purchasing_roles_can_read_order_management(): void
    {
        foreach (['staff_hr', 'hr', 'general_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['is_active' => true])->assignRole($role), ['*']);

            $this->getJson('/api/v1/orders')->assertOk();
        }
    }

    public function test_only_super_admin_can_access_master_data_even_if_other_roles_have_permissions(): void
    {
        $adminRole = Role::findByName('super_admin', 'web');
        $masters = ['companies', 'suppliers', 'categories', 'tax-types', 'tax-rates', 'departments', 'employees'];
        $permissions = collect([
            'companies.view', 'companies.manage', 'suppliers.view', 'suppliers.create',
            'categories.view', 'categories.manage', 'taxes.view', 'taxes.manage',
            'departments.view', 'departments.manage', 'employees.view', 'employees.manage',
            'items.view', 'items.create', 'items.manage', 'po.view',
        ])->map(static fn (string $name): Permission => Permission::findOrCreate($name, 'web'));
        $admin = User::factory()->create(['is_active' => true])->assignRole($adminRole);
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, ['*']);
        foreach ($masters as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk();
        }

        foreach (['hr', 'general_manager', 'staff_hr'] as $role) {
            $actor = User::factory()->create(['is_active' => true])->assignRole($role);
            $actor->givePermissionTo($permissions);
            Sanctum::actingAs($actor, ['*']);

            foreach ($masters as $path) {
                $this->getJson('/api/v1/'.$path)->assertForbidden();
                $this->postJson('/api/v1/'.$path, [])->assertForbidden();
            }
            if ($role !== 'staff_hr') {
                $this->postJson('/api/v1/items', [])->assertForbidden();
                $this->getJson('/api/v1/items/category-options')->assertForbidden();
            }
            $this->getJson('/api/v1/items')->assertOk();
            $this->getJson('/api/v1/purchase-orders/suggestions/companies')->assertOk();
        }
    }

    public function test_staff_hr_can_create_a_stock_item_and_select_active_categories(): void
    {
        $category = Category::query()->create(['code' => 'STAFF-OK', 'name' => 'Kategori Staf Aktif', 'is_active' => true]);
        $inactive = Category::query()->create(['code' => 'STAFF-OFF', 'name' => 'Kategori Staf Nonaktif', 'is_active' => false]);
        $staff = User::factory()->create(['is_active' => true])->assignRole('staff_hr');
        Sanctum::actingAs($staff, ['*']);

        $this->getJson('/api/v1/items/category-options')->assertOk()
            ->assertJsonFragment(['id' => $category->id, 'name' => $category->name])
            ->assertJsonMissing(['id' => $inactive->id, 'name' => $inactive->name]);
        $this->getJson('/api/v1/categories')->assertForbidden();

        $payload = ['category_id' => $category->id, 'sku' => 'STAFF-NEW', 'name' => 'Barang Staf Baru', 'unit' => 'pcs'];
        $this->postJson('/api/v1/items', $payload + ['stock_quantity' => '5'])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');
        $item = $this->postJson('/api/v1/items', $payload)->assertCreated()
            ->assertJsonPath('data.stock_quantity', '0.000')->json('data.id');
        $this->patchJson("/api/v1/items/{$item}", ['name' => 'Nama Baru'])->assertForbidden();
        $this->deleteJson("/api/v1/items/{$item}")->assertForbidden();
        $this->postJson("/api/v1/items/{$item}/stock-adjustments", [])->assertForbidden();
    }

    public function test_staff_hr_ga_can_create_stock_item_with_items_create_but_cannot_edit_or_delete(): void
    {
        $role = Role::findOrCreate('staff_hr_ga', 'web');
        $role->syncPermissions(['items.view', 'items.create', 'items.manage', 'inventory.view']);
        $staff = User::factory()->create(['is_active' => true])->assignRole($role);
        Sanctum::actingAs($staff, ['*']);

        $category = Category::query()->create([
            'code' => 'GA-CAT',
            'name' => 'Kategori Stok GA',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/items/category-options')->assertOk()
            ->assertJsonFragment(['id' => $category->id, 'name' => $category->name]);
        $this->getJson('/api/v1/categories')->assertForbidden();

        $payload = [
            'category_id' => $category->id,
            'sku' => 'GA-NEW-001',
            'name' => 'Barang GA Baru',
            'unit' => 'pcs',
        ];
        $itemId = $this->postJson('/api/v1/items', $payload)->assertCreated()
            ->assertJsonPath('data.stock_quantity', '0.000')
            ->json('data.id');

        $this->assertDatabaseHas('items', ['id' => $itemId, 'sku' => 'GA-NEW-001']);
        $this->patchJson("/api/v1/items/{$itemId}", ['name' => 'Barang Diubah'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/items/{$itemId}")->assertForbidden();
        $this->postJson("/api/v1/items/{$itemId}/stock-adjustments", [])
            ->assertForbidden();
    }

    public function test_staff_hr_ga_without_items_create_cannot_create_even_with_items_manage(): void
    {
        $role = Role::findOrCreate('staff_hr_ga', 'web');
        $role->syncPermissions(['items.view', 'items.manage', 'inventory.view']);
        $staff = User::factory()->create(['is_active' => true])->assignRole($role);
        Sanctum::actingAs($staff, ['*']);

        $category = Category::query()->create([
            'code' => 'GA-NO-CREATE',
            'name' => 'Kategori GA Tanpa Akses Buat',
            'is_active' => true,
        ]);
        $this->getJson('/api/v1/items')->assertOk();
        $this->getJson('/api/v1/items/category-options')->assertForbidden();
        $this->postJson('/api/v1/items', [
            'category_id' => $category->id,
            'sku' => 'GA-NOT-ALLOWED',
            'name' => 'Barang Tidak Diizinkan',
            'unit' => 'pcs',
        ])->assertForbidden();

        $this->assertDatabaseMissing('items', ['sku' => 'GA-NOT-ALLOWED']);
    }

    public function test_user_without_role_or_employee_cannot_enter_api(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_inactive_staff_cannot_use_existing_token(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
        ]);

        $user->assignRole('hr');

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }
}
