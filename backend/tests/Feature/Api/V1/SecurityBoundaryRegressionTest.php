<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Actions\SaveDraftPurchaseOrder;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Boundary checks: guest/403, employee isolation, mass assignment, report visibility. */
final class SecurityBoundaryRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private Item $item;

    private Supplier $supplier;

    private Department $department;

    private Employee $employee;

    private User $employeeUser;

    private int $roleCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach ([
            'items.manage', 'inventory.view', 'inventory.adjust',
            'po.create', 'po.submit', 'po.approve.hr', 'po.view',
            'grn.create', 'grn.confirm', 'distribution.create',
            'distribution.view', 'distribution.approve',
            'reports.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->department = Department::query()->create([
            'code' => 'SEC-DEPT', 'name' => 'Keamanan Integrasi', 'is_active' => true,
        ]);
        $this->employeeUser = User::factory()->create(['is_active' => true]);
        $this->employee = Employee::query()->create([
            'department_id' => $this->department->id,
            'user_id' => $this->employeeUser->id,
            'employee_number' => 'SEC-EMP-1',
            'name' => 'Penerima SEC', 'is_active' => true,
        ]);
        $this->supplier = Supplier::query()->create([
            'code' => 'SEC-SUP', 'name' => 'Supplier SEC',
            'normalized_name' => 'supplier sec', 'is_active' => true,
        ]);
        $category = Category::query()->create([
            'code' => 'SEC-CAT', 'name' => 'Kategori SEC', 'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'SEC-SKU',
            'name' => 'Barang SEC', 'unit' => 'pcs',
            'minimum_stock' => '2.000', 'is_active' => true,
        ]);
    }

    /** @return array{0:User,1:Role} */
    private function actor(array $permissions): array
    {
        $role = Role::findOrCreate('sec_quality_role_'.(++$this->roleCounter), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return [$user, $role];
    }

    private function login(User $actor, array $abilities = ['*']): void
    {
        Sanctum::actingAs($actor, $abilities);
    }

    private function draft(User $maker): int
    {
        $po = app(SaveDraftPurchaseOrder::class)->create([
            'supplier_id' => $this->supplier->id,
            'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id,
            'order_date' => '2026-11-04',
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => '2.000', 'unit_price' => '10000.00',
            ]],
            'tax_rate_ids' => [],
        ], (int) $maker->id);

        return (int) $po->id;
    }

    public function test_guest_cannot_read_business_and_reporting_apis(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/goods-receipts')->assertUnauthorized();
        $this->getJson('/api/v1/stock-movements')->assertUnauthorized();
        $this->getJson('/api/v1/distributions')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
    }

    public function test_inventory_master_cannot_write_stock_balance_or_last_price(): void
    {
        [$manager] = $this->actor(['items.manage']);
        $this->login($manager);

        $this->patchJson("/api/v1/items/{$this->item->id}", [
            'stock_quantity' => '999.000',
            'last_purchase_price' => '1.00',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'stock_quantity', 'last_purchase_price',
        ]);

        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame('0.00', (string) $this->item->fresh()->last_purchase_price);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('item_id', $this->item->id)->count());
    }

    public function test_report_permission_alone_does_not_expose_inventory_details(): void
    {
        [$viewer] = $this->actor(['reports.view']);
        $this->login($viewer);
        $this->getJson('/api/v1/dashboard/low-stock')->assertForbidden();
        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()->assertJsonPath('data.available_sections', []);
    }

    public function test_dynamic_role_permission_change_updates_dashboard_section(): void
    {
        [$viewer, $role] = $this->actor(['reports.view']);
        $this->login($viewer);
        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()->assertJsonPath('data.available_sections', []);

        $role->givePermissionTo('inventory.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->login($viewer->fresh());
        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()->assertJsonPath('data.available_sections', ['inventory']);
    }

    public function test_employee_cannot_view_or_receive_other_employees_distribution(): void
    {
        $this->login($this->employeeUser, ['employee:self']);
        $distributionId = $this->postJson('/api/v1/distributions', [
            'purpose' => 'Penggunaan barang untuk pekerjaan',
            'items' => [['item_id' => $this->item->id, 'quantity' => '1.000']],
        ])->assertCreated()->json('data.id');

        $other = User::factory()->create(['is_active' => true]);
        Employee::query()->create([
            'department_id' => $this->department->id,
            'user_id' => $other->id,
            'employee_number' => 'SEC-EMP-2',
            'name' => 'Karyawan Lain', 'is_active' => true,
        ]);
        $this->login($other, ['employee:self']);
        $this->getJson("/api/v1/distributions/{$distributionId}")->assertForbidden();
        $this->postJson("/api/v1/distributions/{$distributionId}/receive")->assertForbidden();
    }

    public function test_employee_without_self_token_ability_cannot_request_distribution(): void
    {
        $this->login($this->employeeUser, ['some:other-scope']);
        $this->postJson('/api/v1/distributions', [
            'purpose' => 'Kebutuhan kerja pribadi',
            'items' => [['item_id' => $this->item->id, 'quantity' => '1.000']],
        ])->assertForbidden();
    }

    public function test_maker_checker_cannot_be_bypassed_even_with_approval_permission(): void
    {
        [$maker] = $this->actor(['po.create', 'po.submit', 'po.approve.hr']);
        $poId = $this->draft($maker);
        $this->login($maker);
        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$poId}/approvals/hr/approve")
            ->assertForbidden();
        $this->assertSame('pending_approval', DB::table('purchase_orders')->where('id', $poId)->value('status'));
        $this->assertSame(0, DB::table('orders')->where('purchase_order_id', $poId)->count());
    }

    public function test_dashboard_get_is_read_only_and_requires_valid_pagination(): void
    {
        [$viewer] = $this->actor(['reports.view', 'inventory.view']);
        $this->login($viewer);
        $before = [
            'items' => DB::table('items')->where('id', $this->item->id)->value('stock_quantity'),
            'movements' => DB::table('stock_movements')->where('item_id', $this->item->id)->count(),
        ];
        $this->getJson('/api/v1/dashboard/overview')->assertOk();
        $this->getJson('/api/v1/dashboard/activity?per_page=10')->assertOk();
        $this->getJson('/api/v1/dashboard/low-stock?per_page=10')->assertOk();
        $this->getJson('/api/v1/dashboard/low-stock?per_page=101')->assertUnprocessable();
        $this->assertSame((string) $before['items'], (string) $this->item->fresh()->stock_quantity);
        $this->assertSame($before['movements'], DB::table('stock_movements')
            ->where('item_id', $this->item->id)->count());
    }
}
