<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DashboardApiTest extends TestCase
{
    use DatabaseTransactions;

    private int $roleIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach (
            [
                'reports.view',
                'po.view',
                'orders.view',
                'grn.view',
                'inventory.view',
                'distribution.view',
            ] as $name
        ) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array{0:User,1:Role} */
    private function signIn(array $permissions): array
    {
        $role = Role::findOrCreate('report_test_role_'.(++$this->roleIndex), 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return [$user, $role];
    }

    /**
     * Membuat data konkret tanpa memanggil workflow transaksi.
     * Ini fixture laporan saja, bukan contoh API manipulasi stok.
     *
     * @return array{po_id:int,item_id:int,category_id:int,actor_id:int}
     */
    private function fixture(): array
    {
        $actor = User::factory()->create(['is_active' => true]);
        $now = '2026-11-04 10:00:00';
        $old = '2026-10-01 10:00:00';

        $departmentId = DB::table('departments')->insertGetId([
            'code' => 'RPT-DEPT',
            'name' => 'Report Department',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'department_id' => $departmentId,
            'employee_number' => 'RPT-EMP',
            'name' => 'Report Employee',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'RPT-SUP',
            'name' => 'Report Supplier',
            'normalized_name' => 'report supplier',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'code' => 'RPT-CAT',
            'name' => 'Report Category',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $itemId = DB::table('items')->insertGetId([
            'category_id' => $categoryId,
            'sku' => 'RPT-LOW',
            'name' => 'Low Stock Item',
            'unit' => 'pcs',
            'stock_quantity' => '1.000',
            'minimum_stock' => '2.000',
            'last_purchase_price' => '10.00',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('items')->insert([
            [
                'category_id' => $categoryId,
                'sku' => 'RPT-OK',
                'name' => 'Healthy Stock Item',
                'unit' => 'rim',
                'stock_quantity' => '5.000',
                'minimum_stock' => '2.000',
                'last_purchase_price' => '10.00',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'category_id' => $categoryId,
                'sku' => 'RPT-INACTIVE',
                'name' => 'Inactive Stock Item',
                'unit' => 'pcs',
                'stock_quantity' => '0.000',
                'minimum_stock' => '2.000',
                'last_purchase_price' => '10.00',
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $poFields = [
            'supplier_id' => $supplierId,
            'requested_for_employee_id' => $employeeId,
            'requested_for_department_id' => $departmentId,
            'created_by' => $actor->id,
            'supplier_name_snapshot' => 'Report Supplier',
            'supplier_code_snapshot' => 'RPT-SUP',
        ];
        $poId = DB::table('purchase_orders')->insertGetId([
            ...$poFields,
            'po_number' => 'PO-RPT-1',
            'order_date' => '2026-11-04',
            'status' => 'sent',
            'total_amount' => '250.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('purchase_orders')->insert([
            ...$poFields,
            'po_number' => 'PO-RPT-OLD',
            'order_date' => '2026-10-01',
            'status' => 'draft',
            'total_amount' => '100.00',
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        DB::table('purchase_orders')->insert([
            ...$poFields,
            'po_number' => 'PO-RPT-DELETED',
            'order_date' => '2026-11-04',
            'status' => 'cancelled',
            'total_amount' => '9999.00',
            'deleted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('orders')->insert([
            'purchase_order_id' => $poId,
            'status' => 'partially_received',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('goods_receipts')->insert([
            [
                'grn_number' => 'GRN-RPT-1',
                'purchase_order_id' => $poId,
                'created_by' => $actor->id,
                'confirmed_by' => null,
                'received_date' => '2026-11-04',
                'status' => 'pending',
                'confirmed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'grn_number' => 'GRN-RPT-2',
                'purchase_order_id' => $poId,
                'created_by' => $actor->id,
                'confirmed_by' => $actor->id,
                'received_date' => '2026-11-04',
                'status' => 'discrepancy',
                'confirmed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('distributions')->insert([
            [
                'distribution_number' => 'DIST-RPT-1',
                'employee_id' => $employeeId,
                'requested_by' => $actor->id,
                'purpose' => 'Perlengkapan kantor',
                'status' => 'requested',
                'requested_at' => $now,
                'sent_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'distribution_number' => 'DIST-RPT-2',
                'employee_id' => $employeeId,
                'requested_by' => $actor->id,
                'purpose' => 'Perlengkapan kerja',
                'status' => 'sent',
                'requested_at' => $now,
                'sent_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        $distributionId = DB::table('distributions')
            ->where('distribution_number', 'DIST-RPT-1')->value('id');
        DB::table('purchase_order_events')->insert([
            'purchase_order_id' => $poId,
            'actor_id' => $actor->id,
            'action' => 'submitted',
            'from_status' => 'draft',
            'to_status' => 'pending_approval',
            'created_at' => $now,
        ]);
        DB::table('distribution_events')->insert([
            'distribution_id' => $distributionId,
            'actor_id' => $actor->id,
            'action' => 'requested',
            'from_status' => null,
            'to_status' => 'requested',
            'created_at' => $now,
        ]);
        DB::table('stock_movements')->insert([
            'item_id' => $itemId,
            'created_by' => $actor->id,
            'movement_type' => 'adjustment',
            'quantity_change' => '1.000',
            'balance_before' => '0.000',
            'balance_after' => '1.000',
            'reason' => 'Fixture laporan',
            'created_at' => $now,
        ]);

        return [
            'po_id' => (int) $poId,
            'item_id' => (int) $itemId,
            'category_id' => (int) $categoryId,
            'actor_id' => (int) $actor->id,
        ];
    }

    public function test_guest_cannot_view_overview(): void
    {
        $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
    }

    public function test_guest_cannot_view_activity(): void
    {
        $this->getJson('/api/v1/dashboard/activity')->assertUnauthorized();
    }

    public function test_guest_cannot_view_low_stock(): void
    {
        $this->getJson('/api/v1/dashboard/low-stock')->assertUnauthorized();
    }

    public function test_reports_permission_is_required_even_with_module_permission(): void
    {
        $this->signIn(['inventory.view', 'po.view']);
        $this->getJson('/api/v1/dashboard/overview')->assertForbidden();
        $this->getJson('/api/v1/dashboard/activity')->assertForbidden();
        $this->getJson('/api/v1/dashboard/low-stock')->assertForbidden();
    }

    public function test_reports_only_does_not_expose_any_module(): void
    {
        $this->fixture();
        $this->signIn(['reports.view']);

        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.available_sections', [])
            ->assertJsonPath('data.sections', []);

        $this->getJson('/api/v1/dashboard/activity')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_po_viewer_sees_only_po_section_and_correct_money(): void
    {
        $this->fixture();
        $this->signIn(['reports.view', 'po.view']);

        $result = $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.sections.purchase_orders.total', 2)
            ->assertJsonPath('data.sections.purchase_orders.by_status.sent', 1)
            ->assertJsonPath('data.sections.purchase_orders.by_status.draft', 1)
            ->assertJsonPath('data.sections.purchase_orders.total_amount_all_statuses', '350.00')
            ->assertJsonPath('data.sections.purchase_orders.approved_or_later_amount', '250.00');

        $this->assertSame(['purchase_orders'], $result->json('data.available_sections'));
        $this->assertArrayNotHasKey('inventory', $result->json('data.sections'));
        $this->assertArrayNotHasKey('distributions', $result->json('data.sections'));
    }

    public function test_all_authorized_sections_show_expected_aggregates(): void
    {
        $this->fixture();
        $this->signIn([
            'reports.view',
            'po.view',
            'orders.view',
            'grn.view',
            'inventory.view',
            'distribution.view',
        ]);

        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.sections.orders.total', 1)
            ->assertJsonPath('data.sections.orders.by_status.partially_received', 1)
            ->assertJsonPath('data.sections.goods_receipts.total', 2)
            ->assertJsonPath('data.sections.goods_receipts.by_status.pending', 1)
            ->assertJsonPath('data.sections.goods_receipts.by_status.discrepancy', 1)
            ->assertJsonPath('data.sections.goods_receipts.confirmed_count', 1)
            ->assertJsonPath('data.sections.distributions.total', 2)
            ->assertJsonPath('data.sections.distributions.by_status.requested', 1)
            ->assertJsonPath('data.sections.distributions.by_status.sent', 1);
    }

    public function test_date_range_filters_documents_without_changing_stock_snapshot(): void
    {
        $this->fixture();
        $this->signIn(['reports.view', 'po.view', 'inventory.view']);

        $this->getJson('/api/v1/dashboard/overview?from=2026-11-04&to=2026-11-04')
            ->assertOk()
            ->assertJsonPath('data.sections.purchase_orders.total', 1)
            ->assertJsonPath('data.sections.purchase_orders.total_amount_all_statuses', '250.00')
            ->assertJsonPath('data.sections.inventory.low_stock_items', 1)
            ->assertJsonPath('data.sections.inventory.movement_count', 1);

        $this->getJson('/api/v1/dashboard/overview?from=2026-10-01&to=2026-10-01')
            ->assertOk()
            ->assertJsonPath('data.sections.purchase_orders.total', 1)
            ->assertJsonPath('data.sections.purchase_orders.by_status.draft', 1)
            ->assertJsonPath('data.sections.inventory.low_stock_items', 1)
            ->assertJsonPath('data.sections.inventory.movement_count', 0);
    }

    public function test_date_filters_reject_missing_pair_and_reverse_range(): void
    {
        $this->signIn(['reports.view']);

        $this->getJson('/api/v1/dashboard/overview?from=2026-11-04')
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/dashboard/overview?to=2026-11-04')
            ->assertUnprocessable()->assertJsonValidationErrors('from');

        $this->getJson('/api/v1/dashboard/overview?from=2026-11-10&to=2026-11-04')
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/dashboard/activity?from=invalid&to=2026-11-04')
            ->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_inventory_metrics_are_current_snapshot_and_movement_counts(): void
    {
        $this->fixture();
        $this->signIn(['reports.view', 'inventory.view']);

        $result = $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.sections.inventory.active_items', 2)
            ->assertJsonPath('data.sections.inventory.low_stock_items', 1)
            ->assertJsonPath('data.sections.inventory.movement_count', 1)
            ->assertJsonPath('data.sections.inventory.movement_by_type.adjustment', 1);

        $this->assertArrayNotHasKey('purchase_orders', $result->json('data.sections'));
    }

    public function test_low_stock_requires_inventory_permission(): void
    {
        $this->fixture();
        $this->signIn(['reports.view', 'po.view']);
        $this->getJson('/api/v1/dashboard/low-stock')->assertForbidden();
    }

    public function test_low_stock_details_are_paginated_and_searchable(): void
    {
        $ids = $this->fixture();
        $this->signIn(['reports.view', 'inventory.view']);

        $this->getJson('/api/v1/dashboard/low-stock?search=RPT-LOW&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'RPT-LOW')
            ->assertJsonPath('data.0.stock_quantity', '1.000')
            ->assertJsonPath('meta.pagination.total', 1);

        $this->getJson('/api/v1/dashboard/low-stock?category_id='.$ids['category_id'])
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);

        $this->getJson('/api/v1/dashboard/low-stock?per_page=1000')
            ->assertUnprocessable();
    }

    public function test_activity_hides_all_sources_without_module_permission(): void
    {
        $this->fixture();
        $this->signIn(['reports.view', 'po.view']);

        $result = $this->getJson('/api/v1/dashboard/activity')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'purchase_order');

        $this->assertArrayNotHasKey('notes', $result->json('data.0'));
    }

    public function test_activity_includes_only_inventory_source_for_inventory_viewer(): void
    {
        $this->fixture();
        $this->signIn(['reports.view', 'inventory.view']);

        $this->getJson('/api/v1/dashboard/activity')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.source', 'stock_movement')
            ->assertJsonPath('data.0.action', 'adjustment');
    }

    public function test_activity_unions_four_sources_with_pagination(): void
    {
        $this->fixture();
        $this->signIn([
            'reports.view',
            'po.view',
            'grn.view',
            'distribution.view',
            'inventory.view',
        ]);

        $result = $this->getJson('/api/v1/dashboard/activity?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.per_page', 2);

        $this->assertSame(3, $result->json('meta.pagination.last_page'));

        $this->getJson('/api/v1/dashboard/activity?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_activity_filter_excludes_events_outside_date(): void
    {
        $this->fixture();
        $this->signIn(['reports.view', 'po.view', 'grn.view']);

        $this->getJson('/api/v1/dashboard/activity?from=2026-11-04&to=2026-11-04')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3);

        $this->getJson('/api/v1/dashboard/activity?from=2026-10-01&to=2026-10-01')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_permission_change_without_hardcoded_role_updates_visibility(): void
    {
        $this->fixture();
        [$user, $role] = $this->signIn(['reports.view']);

        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()->assertJsonPath('data.available_sections', []);

        $role->givePermissionTo('po.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh(), ['*']);

        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.available_sections.0', 'purchase_orders');
    }

    public function test_get_reports_do_not_modify_business_tables(): void
    {
        $ids = $this->fixture();
        $this->signIn([
            'reports.view',
            'po.view',
            'orders.view',
            'grn.view',
            'inventory.view',
            'distribution.view',
        ]);

        $initial = [
            'stock' => DB::table('items')->where('id', $ids['item_id'])->value('stock_quantity'),
            'movements' => DB::table('stock_movements')->count(),
            'po_events' => DB::table('purchase_order_events')->count(),
            'distribution_events' => DB::table('distribution_events')->count(),
            'grn' => DB::table('goods_receipts')->count(),
        ];

        $this->getJson('/api/v1/dashboard/overview')->assertOk();
        $this->getJson('/api/v1/dashboard/activity')->assertOk();
        $this->getJson('/api/v1/dashboard/low-stock')->assertOk();

        $this->assertSame(
            (string) $initial['stock'],
            (string) DB::table('items')->where('id', $ids['item_id'])->value('stock_quantity')
        );
        $this->assertSame($initial['movements'], DB::table('stock_movements')->count());
        $this->assertSame($initial['po_events'], DB::table('purchase_order_events')->count());
        $this->assertSame($initial['distribution_events'], DB::table('distribution_events')->count());
        $this->assertSame($initial['grn'], DB::table('goods_receipts')->count());
    }
}
