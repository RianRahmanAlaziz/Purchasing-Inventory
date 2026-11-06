<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Actions\SaveDraftPurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PurchaseOrderApiTest extends TestCase
{
    use DatabaseTransactions;

    private Supplier $supplier;

    private Department $department;

    private Employee $employee;

    private Item $item;

    private int $roleIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach ([
            'po.view', 'po.create', 'po.update', 'po.submit', 'po.send', 'po.supplier-confirm',
            'po.approve.hr', 'po.approve.gm', 'po.reject.hr', 'po.reject.gm',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->department = Department::query()->create([
            'code' => 'PO-DEPT', 'name' => 'PO Department', 'is_active' => true,
        ]);
        $this->employee = Employee::query()->create([
            'employee_number' => 'PO-EMP-001', 'department_id' => $this->department->id,
            'name' => 'Pemohon PO', 'is_active' => true,
        ]);
        $this->supplier = Supplier::query()->create([
            'code' => 'PO-SUP', 'name' => 'Supplier Uji',
            'normalized_name' => 'supplier uji', 'is_active' => true,
        ]);
        $category = Category::query()->create([
            'code' => 'PO-CAT', 'name' => 'Kategori Pengadaan', 'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'PO-SKU-01',
            'name' => 'Barang Pengujian', 'unit' => 'pcs',
            'minimum_stock' => '5.000', 'is_active' => true,
        ]);
    }

    private function actor(array $permissions): User
    {
        $role = Role::findOrCreate('po_dynamic_test_'.(++$this->roleIndex), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function payload(string $price = '1000000.00'): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id,
            'order_date' => '2026-11-04',
            'expected_date' => '2026-11-20',
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => '1.000',
                'unit_price' => $price,
            ]],
            'tax_rate_ids' => [],
        ];
    }

    private function draft(User $maker, string $price = '1000000.00', ?array $payload = null): PurchaseOrder
    {
        return app(SaveDraftPurchaseOrder::class)->create($payload ?? $this->payload($price), (int) $maker->id);
    }

    private function submitAs(User $maker, PurchaseOrder $po): void
    {
        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/submit")->assertOk();
    }

    private function approveAs(User $approver, PurchaseOrder $po, string $step): void
    {
        $this->signIn($approver);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/{$step}/approve")->assertOk();
    }

    public function test_guest_cannot_read_purchase_orders(): void
    {
        $this->getJson('/api/v1/purchase-orders')->assertUnauthorized();
    }

    public function test_role_without_create_permission_cannot_create_po(): void
    {
        $this->signIn($this->actor(['po.view']));
        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertForbidden();
    }

    public function test_can_create_draft_and_preserve_snapshots_without_stock_mutation(): void
    {
        $maker = $this->actor(['po.create']);
        $this->signIn($maker);
        $this->postJson('/api/v1/purchase-orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.sku', 'PO-SKU-01')
            ->assertJsonPath('data.subtotal', '1000000.00')
            ->assertJsonPath('data.order', null);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_po_requires_at_least_one_item(): void
    {
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload();
        $payload['items'] = [];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_po_rejects_inactive_supplier(): void
    {
        $this->supplier->update(['is_active' => false]);
        $this->signIn($this->actor(['po.create']));
        $this->postJson('/api/v1/purchase-orders', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    public function test_po_rejects_employee_department_mismatch(): void
    {
        $other = Department::query()->create(['code' => 'PO-OTHER', 'name' => 'Another PO Department', 'is_active' => true]);
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload();
        $payload['requested_for_department_id'] = $other->id;
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('requested_for_department_id');
    }

    public function test_duplicate_item_id_is_rejected(): void
    {
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload();
        $payload['items'][] = $payload['items'][0];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.item_id');
    }

    public function test_tax_snapshots_and_approval_basis_are_calculated_exactly(): void
    {
        $addition = TaxType::query()->create(['code' => 'PO-TADD', 'name' => 'Pajak Tambah Tes', 'direction' => 'addition', 'is_active' => true]);
        $withholding = TaxType::query()->create(['code' => 'PO-TWH', 'name' => 'Pajak Potong Tes', 'direction' => 'withholding', 'is_active' => true]);
        $addRate = TaxRate::query()->create([
            'tax_type_id' => $addition->id, 'label' => '5% contoh',
            'rate_percent' => '5.0000', 'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-01-01', 'is_active' => true,
        ]);
        $whRate = TaxRate::query()->create([
            'tax_type_id' => $withholding->id, 'label' => '2% contoh',
            'rate_percent' => '2.0000', 'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-01-01', 'is_active' => true,
        ]);
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload('3000000.00');
        $payload['items'][0]['quantity'] = '2.000';
        $payload['tax_rate_ids'] = [$addRate->id, $whRate->id];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '6000000.00')
            ->assertJsonPath('data.tax_addition_total', '300000.00')
            ->assertJsonPath('data.tax_withholding_total', '120000.00')
            ->assertJsonPath('data.approval_basis_amount', '6300000.00')
            ->assertJsonPath('data.total_amount', '6180000.00')
            ->assertJsonPath('data.requires_gm_approval', false);
    }

    public function test_below_twenty_million_needs_only_hr(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.approve.hr']);
        $po = $this->draft($maker, '19999999.99');
        $this->assertSame('20000000.00', $po->approval_threshold_amount);
        $this->assertFalse($po->requires_gm_approval);

        $this->submitAs($maker, $po);
        $this->assertDatabaseCount('purchase_order_approvals', 1);
        $this->assertDatabaseHas('purchase_order_approvals', ['purchase_order_id' => $po->id, 'role_name' => 'hr']);
        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'approved']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_exactly_twenty_million_needs_only_hr_and_creates_one_order(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.approve.hr']);
        $po = $this->draft($maker, '20000000.00');
        $this->submitAs($maker, $po);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'requires_gm_approval' => false]);
        $this->assertDatabaseCount('purchase_order_approvals', 1);
        $this->assertDatabaseCount('orders', 0);

        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'approved']);
        $this->assertDatabaseHas('orders', ['purchase_order_id' => $po->id, 'status' => 'pending']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_above_twenty_million_requires_hr_then_gm(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.approve.hr']);
        $gm = $this->actor(['po.approve.gm']);
        $po = $this->draft($maker, '20000000.01');
        $this->submitAs($maker, $po);
        $this->assertDatabaseCount('purchase_order_approvals', 2);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'requires_gm_approval' => true, 'approval_threshold_amount' => '20000000.00']);

        $this->signIn($gm);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/gm/approve")
            ->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);

        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'pending_approval']);
        $this->assertDatabaseCount('orders', 0);

        $this->approveAs($gm, $po, 'gm');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'approved']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_creator_cannot_approve_own_po_even_with_permission(): void
    {
        $maker = $this->actor(['po.submit', 'po.approve.hr']);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/approve")
            ->assertForbidden();
        $this->assertDatabaseHas('purchase_order_approvals', ['purchase_order_id' => $po->id, 'status' => 'pending']);
    }

    public function test_approval_responses_include_reviewer_names(): void
    {
        $maker = $this->actor(['po.submit', 'po.view']);
        $hr = $this->actor(['po.approve.hr']);
        $gm = $this->actor(['po.approve.gm']);
        $po = $this->draft($maker, '20000000.01');

        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', null)
            ->assertJsonPath('data.approvals.1.decided_by_name', null);

        $this->signIn($hr);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/approve")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.approvals.1.decided_by_name', null);

        $this->signIn($gm);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/gm/approve")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.approvals.1.decided_by_name', $gm->name);

        $hr->update(['is_active' => false]);
        $this->signIn($maker);
        $this->getJson("/api/v1/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.approvals.1.decided_by_name', $gm->name);
        $this->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('data.0.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.0.approvals.1.decided_by_name', $gm->name);
    }

    public function test_user_without_hr_approval_permission_cannot_approve(): void
    {
        $maker = $this->actor(['po.submit']);
        $unauthorized = $this->actor(['po.view']);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->signIn($unauthorized);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/approve")->assertForbidden();
    }

    public function test_same_approval_replay_is_idempotent(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.approve.hr']);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->submitAs($maker, $po); // replay submit, tidak membuat step kedua
        $this->assertDatabaseCount('purchase_order_approvals', 1);
        $this->approveAs($hr, $po, 'hr');
        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, DB::table('purchase_order_events')
            ->where('purchase_order_id', $po->id)->where('action', 'fully_approved')->count());
    }

    public function test_draft_can_update_but_submitted_cannot(): void
    {
        $maker = $this->actor(['po.update', 'po.submit']);
        $po = $this->draft($maker);
        $this->signIn($maker);
        $this->patchJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'Revisi draft'])
            ->assertOk()->assertJsonPath('data.notes', 'Revisi draft');
        $this->submitAs($maker, $po);
        $this->patchJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'Tidak boleh'])
            ->assertStatus(409);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'notes' => 'Revisi draft']);
    }

    public function test_hr_rejection_requires_reason_and_blocks_order(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.reject.hr']);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->signIn($hr);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/reject", ['reason' => 'Tidak sesuai'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_is_confirmed_when_supplier_confirms_and_stock_remains_unchanged(): void
    {
        $maker = $this->actor(['po.submit', 'po.send', 'po.supplier-confirm']);
        $hr = $this->actor(['po.approve.hr']);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->approveAs($hr, $po, 'hr');
        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")
            ->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/v1/purchase-orders/{$po->id}/supplier-confirm")
            ->assertOk()->assertJsonPath('data.status', 'received_by_supplier')
            ->assertJsonPath('data.order.status', 'confirmed');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
    }

    public function test_send_before_approval_is_rejected_and_rolled_back(): void
    {
        $maker = $this->actor(['po.send']);
        $po = $this->draft($maker);
        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertStatus(409);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'draft']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cancel_approved_po_also_cancels_order(): void
    {
        $maker = $this->actor(['po.submit', 'po.update']);
        $hr = $this->actor(['po.approve.hr']);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->approveAs($hr, $po, 'hr');
        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel", ['reason' => 'Pembelian tidak jadi'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.order.status', 'cancelled');
    }

    public function test_draft_can_be_soft_deleted_but_approved_cannot(): void
    {
        $maker = $this->actor(['po.submit', 'po.update']);
        $hr = $this->actor(['po.approve.hr']);
        $unused = $this->draft($maker);
        $this->signIn($maker);
        $this->deleteJson("/api/v1/purchase-orders/{$unused->id}")->assertOk();
        $this->assertSoftDeleted('purchase_orders', ['id' => $unused->id]);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->approveAs($hr, $po, 'hr');
        $this->signIn($maker);
        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(409);
    }

    public function test_po_rejects_tax_rate_outside_order_date(): void
    {
        $type = TaxType::query()->create([
            'code' => 'PO-FUTURE', 'name' => 'Pajak Masa Depan',
            'direction' => 'addition', 'is_active' => true,
        ]);
        $rate = TaxRate::query()->create([
            'tax_type_id' => $type->id, 'label' => 'Tarif belum berlaku',
            'rate_percent' => '5.0000', 'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-11-05', 'is_active' => true,
        ]);
        $maker = $this->actor(['po.create']);
        $this->signIn($maker);
        $payload = $this->payload();
        $payload['tax_rate_ids'] = [$rate->id];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('tax_rate_ids');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_hr_and_gm_steps_cannot_be_decided_by_same_person(): void
    {
        $maker = $this->actor(['po.submit']);
        $both = $this->actor(['po.approve.hr', 'po.approve.gm']);
        $po = $this->draft($maker, '20000000.01');
        $this->submitAs($maker, $po);
        $this->approveAs($both, $po, 'hr');
        $this->signIn($both);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/gm/approve")
            ->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'pending_approval']);
    }

    public function test_po_calculation_snapshot_survives_supplier_rename(): void
    {
        $maker = $this->actor(['po.view']);
        $po = $this->draft($maker);
        $this->supplier->update(['name' => 'Nama Baru', 'normalized_name' => 'nama baru']);
        $this->signIn($maker);
        $this->getJson("/api/v1/purchase-orders/{$po->id}")
            ->assertOk()->assertJsonPath('data.supplier_name_snapshot','Supplier Uji');
    }
}
