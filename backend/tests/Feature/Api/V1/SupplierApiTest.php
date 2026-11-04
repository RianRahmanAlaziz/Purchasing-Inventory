<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Support\SupplierName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SupplierApiTest extends TestCase
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

        foreach (
            [
                'suppliers.view',
                'suppliers.create',
                'suppliers.update',
                'suppliers.delete',
                'po.view',
            ] as $permission
        ) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actAsPermissions(array $permissions): User
    {
        $role = Role::findOrCreate('supplier_feature_tester', 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function supplier(
        string $code,
        string $name,
        bool $active = true
    ): Supplier {
        return Supplier::query()->create([
            'code' => $code,
            'name' => $name,
            'normalized_name' => SupplierName::normalized($name),
            'is_active' => $active,
        ]);
    }

    private function makePurchaseOrder(Supplier $supplier, User $actor): int
    {
        $department = Department::query()->create([
            'code' => 'OPS-PO',
            'name' => 'Operational PO',
            'is_active' => true,
        ]);

        $employee = Employee::query()->create([
            'department_id' => $department->id,
            'employee_number' => 'EMP-PO-001',
            'name' => 'Karyawan PO',
            'is_active' => true,
        ]);

        return DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-SUP-0001',
            'supplier_id' => $supplier->id,
            'requested_for_employee_id' => $employee->id,
            'requested_for_department_id' => $department->id,
            'created_by' => $actor->id,
            'supplier_name_snapshot' => $supplier->name,
            'supplier_code_snapshot' => $supplier->code,
            'order_date' => '2026-11-04',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_cannot_view_suppliers(): void
    {
        $this->getJson('/api/v1/suppliers')->assertUnauthorized();
    }

    public function test_viewer_cannot_create_or_delete_suppliers(): void
    {
        $this->actAsPermissions(['suppliers.view']);
        $this->supplier('SUP-A', 'PT Mandiri');

        $this->getJson('/api/v1/suppliers')->assertOk();
        $this->postJson('/api/v1/suppliers', [
            'name' => 'PT Baru',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/suppliers/1')->assertForbidden();
    }

    public function test_manager_creates_supplier_without_typing_code(): void
    {
        $this->actAsPermissions(['suppliers.create']);

        $this->postJson('/api/v1/suppliers', [
            'name' => '  PT  Sumber   Makmur  ',
            'email' => '  Sales@Example.COM ',
            'normalized_name' => 'client-fake-value',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'PT Sumber Makmur')
            ->assertJsonPath('data.email', 'sales@example.com');

        $this->assertDatabaseHas('suppliers', [
            'name' => 'PT Sumber Makmur',
            'normalized_name' => 'pt sumber makmur',
        ]);

        $this->assertTrue(
            str_starts_with(Supplier::query()->firstOrFail()->code, 'SUP-')
        );
    }

    public function test_duplicate_supplier_name_is_rejected_after_normalization(): void
    {
        $this->actAsPermissions(['suppliers.create']);
        $this->supplier('SUP-001', 'PT Sumber Makmur');

        $this->postJson('/api/v1/suppliers', [
            'name' => '  pt   SUMBER makmur  ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_duplicate_supplier_code_is_rejected(): void
    {
        $this->actAsPermissions(['suppliers.create']);
        $this->supplier('SUP-001', 'PT Sumber Makmur');

        $this->postJson('/api/v1/suppliers', [
            'code' => 'sup-001',
            'name' => 'PT Lain',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_suggest_returns_only_active_matching_suppliers(): void
    {
        $this->actAsPermissions(['suppliers.view']);
        $this->supplier('SUP-001', 'PT Sumber Makmur');
        $this->supplier('SUP-002', 'PT Sumber Lama', false);
        $this->supplier('SUP-003', 'CV Sejahtera');

        $this->getJson('/api/v1/suppliers/suggest?q=sUmBer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'PT Sumber Makmur');

        $this->getJson('/api/v1/suppliers/suggest?q=a')
            ->assertUnprocessable();
    }

    public function test_list_can_filter_active_suppliers(): void
    {
        $this->actAsPermissions(['suppliers.view']);
        $this->supplier('SUP-001', 'PT Sumber Makmur');
        $this->supplier('SUP-002', 'PT Sumber Lama', false);
        $this->supplier('SUP-003', 'CV Sejahtera');

        $this->getJson('/api/v1/suppliers?search=Sumber&is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_history_requires_po_view_and_preserves_supplier_snapshot(): void
    {
        $actor = $this->actAsPermissions([
            'suppliers.view',
            'suppliers.update',
        ]);
        $supplier = $this->supplier('SUP-001', 'PT Nama Lama');
        $poId = $this->makePurchaseOrder($supplier, $actor);

        $this->getJson("/api/v1/suppliers/{$supplier->id}/purchase-orders")
            ->assertForbidden();

        $this->patchJson("/api/v1/suppliers/{$supplier->id}", [
            'name' => 'PT Nama Baru',
        ])->assertOk();

        $actor->roles->firstOrFail()->givePermissionTo('po.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson("/api/v1/suppliers/{$supplier->id}/purchase-orders")
            ->assertOk()
            ->assertJsonPath('data.0.id', $poId)
            ->assertJsonPath('data.0.supplier_name_at_order', 'PT Nama Lama')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_client_cannot_modify_normalized_name_directly(): void
    {
        $this->actAsPermissions(['suppliers.update']);
        $supplier = $this->supplier('SUP-001', 'PT Aman');

        $this->patchJson("/api/v1/suppliers/{$supplier->id}", [
            'normalized_name' => 'nama-palsu',
            'phone' => '08123456789',
        ])->assertOk();

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'normalized_name' => 'pt aman',
        ]);
    }

    public function test_supplier_with_po_cannot_be_deleted(): void
    {
        $actor = $this->actAsPermissions(['suppliers.delete']);
        $supplier = $this->supplier('SUP-001', 'PT Sumber Makmur');
        $this->makePurchaseOrder($supplier, $actor);

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_supplier_without_po_can_be_deleted(): void
    {
        $this->actAsPermissions(['suppliers.delete']);
        $supplier = $this->supplier('SUP-001', 'PT Sumber Makmur');

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertOk();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_supplier_can_be_deactivated_without_deleting_history(): void
    {
        $actor = $this->actAsPermissions([
            'suppliers.update',
            'suppliers.view',
            'po.view',
        ]);
        $supplier = $this->supplier('SUP-001', 'PT Sumber Makmur');
        $this->makePurchaseOrder($supplier, $actor);

        $this->patchJson("/api/v1/suppliers/{$supplier->id}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->getJson("/api/v1/suppliers/{$supplier->id}/purchase-orders")
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
