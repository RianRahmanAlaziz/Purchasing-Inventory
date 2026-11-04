<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domain\Purchasing\Actions\SaveDraftPurchaseOrder;
use App\Domain\Purchasing\Exceptions\OrderConflict;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Services\OrderStatusSync;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class OrderApiTest extends TestCase
{
    use DatabaseTransactions;

    private Department $department;

    private Employee $employee;

    private Supplier $supplier;

    private Item $item;

    private User $maker;

    private User $hr;

    private int $roleIndex = 0;

    private int $receiptIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach (['orders.view', 'po.approve.hr'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->department = Department::query()->create([
            'code' => 'ORD-DEPT', 'name' => 'Order Department', 'is_active' => true,
        ]);
        $this->employee = Employee::query()->create([
            'employee_number' => 'ORD-EMP-001', 'department_id' => $this->department->id,
            'name' => 'Penerima Order', 'is_active' => true,
        ]);
        $this->supplier = Supplier::query()->create([
            'code' => 'ORD-SUP', 'name' => 'Supplier Order',
            'normalized_name' => 'supplier order', 'is_active' => true,
        ]);
        $category = Category::query()->create([
            'code' => 'ORD-CAT', 'name' => 'Kategori Order', 'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'category_id' => $category->id,
            'sku' => 'ORD-SKU', 'name' => 'Barang Order', 'unit' => 'pcs',
            'minimum_stock' => '2.000', 'is_active' => true,
        ]);

        $this->maker = User::factory()->create(['is_active' => true]);
        $this->hr = $this->roleUser(['po.approve.hr']);
    }

    private function roleUser(array $permissions): User
    {
        $role = Role::findOrCreate('dynamic_order_role_'.(++$this->roleIndex), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function approvedOrder(?int $supplierId = null): Order
    {
        $po = app(SaveDraftPurchaseOrder::class)->create([
            'supplier_id' => $supplierId ?? $this->supplier->id,
            'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id,
            'order_date' => '2026-11-04',
            'expected_date' => '2026-11-20',
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => '10.000',
                'unit_price' => '50000.00',
            ]],
            'tax_rate_ids' => [],
        ], (int) $this->maker->id);

        $workflow = app(PurchaseOrderWorkflow::class);
        $workflow->submit($po, $this->maker);
        $workflow->approve($po, $this->hr, 'hr');

        return Order::query()->where('purchase_order_id', $po->id)->firstOrFail();
    }

    private function send(Order $order, bool $supplierConfirm = false): void
    {
        $po = PurchaseOrder::query()->findOrFail($order->purchase_order_id);
        app(PurchaseOrderWorkflow::class)->send($po, $this->maker);
        if ($supplierConfirm) {
            app(PurchaseOrderWorkflow::class)->supplierConfirm($po, $this->maker);
        }
    }

    /** @return array{grn_id: int, grn_item_id: int} */
    private function receipt(
        Order $order,
        string $received,
        string $damaged = '0.000',
        bool $confirmed = true,
        string $status = 'partially_received',
        bool $applyStock = true,
    ): array {
        $poItem = DB::table('purchase_order_items')
            ->where('purchase_order_id', $order->purchase_order_id)->first();
        $this->assertNotNull($poItem);

        $grnId = DB::table('goods_receipts')->insertGetId([
            'grn_number' => 'GRN-ORDER-'.(++$this->receiptIndex),
            'purchase_order_id' => $order->purchase_order_id,
            'created_by' => $this->maker->id,
            'confirmed_by' => $confirmed ? $this->hr->id : null,
            'received_date' => '2026-11-04',
            'status' => $confirmed ? $status : 'pending',
            'confirmed_at' => $confirmed ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $grnItemId = DB::table('goods_receipt_items')->insertGetId([
            'goods_receipt_id' => $grnId,
            'purchase_order_item_id' => $poItem->id,
            'qty_received' => $received,
            'qty_damaged' => $damaged,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($confirmed && $applyStock) {
            app(StockService::class)->receiveConfirmedGrnItem($grnItemId, (int) $this->hr->id);
        }

        return ['grn_id' => $grnId, 'grn_item_id' => $grnItemId];
    }

    public function test_guest_cannot_list_orders(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_role_without_permission_cannot_list_orders(): void
    {
        $this->signIn($this->roleUser([]));
        $this->getJson('/api/v1/orders')->assertForbidden();
    }

    public function test_dynamic_role_with_orders_view_can_list_orders(): void
    {
        $order = $this->approvedOrder();
        $this->signIn($this->roleUser(['orders.view']));

        $this->getJson('/api/v1/orders')
            ->assertOk()->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_approved_po_creates_exactly_one_pending_order(): void
    {
        $order = $this->approvedOrder();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('pending', $order->status);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_order_list_filters_by_status_supplier_and_po_number(): void
    {
        $first = $this->approvedOrder();
        $this->send($first, supplierConfirm: true);
        $otherSupplier = Supplier::query()->create([
            'code' => 'ORD-SUP-2', 'name' => 'Supplier Kedua',
            'normalized_name' => 'supplier kedua', 'is_active' => true,
        ]);
        $this->approvedOrder($otherSupplier->id);

        $this->signIn($this->roleUser(['orders.view']));
        $poNumber = PurchaseOrder::query()->findOrFail($first->purchase_order_id)->po_number;
        $this->getJson('/api/v1/orders?status=confirmed&supplier_id='.$this->supplier->id.'&po_number='.$poNumber)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_order_list_supports_pagination(): void
    {
        $this->approvedOrder();
        $this->approvedOrder();
        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders?per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.current_page', 2);
    }

    public function test_summary_counts_order_statuses(): void
    {
        $this->approvedOrder();
        $second = $this->approvedOrder();
        $this->send($second, supplierConfirm: true);
        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders/summary')
            ->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.by_status.pending', 1)
            ->assertJsonPath('data.by_status.confirmed', 1);
    }

    public function test_mixed_units_are_not_added_together_in_order_progress(): void
    {
        $itemKg = Item::query()->create([
            'category_id' => $this->item->category_id,
            'sku' => 'ORD-SKU-KG', 'name' => 'Barang Kilogram', 'unit' => 'kg',
            'minimum_stock' => '1.000', 'is_active' => true,
        ]);
        $po = app(SaveDraftPurchaseOrder::class)->create([
            'supplier_id' => $this->supplier->id,
            'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id,
            'order_date' => '2026-11-04',
            'items' => [
                ['item_id' => $this->item->id, 'quantity' => '10.000', 'unit_price' => '50000.00'],
                ['item_id' => $itemKg->id, 'quantity' => '2.000', 'unit_price' => '10000.00'],
            ],
            'tax_rate_ids' => [],
        ], (int) $this->maker->id);
        $workflow = app(PurchaseOrderWorkflow::class);
        $workflow->submit($po, $this->maker);
        $workflow->approve($po, $this->hr, 'hr');
        $order = Order::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.receiving_progress.ordered_quantity', null)
            ->assertJsonCount(2, 'data.receiving_progress.quantities_by_unit');
    }

    public function test_pending_grn_is_listed_but_does_not_count_as_received(): void
    {
        $order = $this->approvedOrder();
        $this->send($order, supplierConfirm: true);
        $this->receipt($order, '4.000', confirmed: false);
        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.receiving_progress.pending_grn_count', 1)
            ->assertJsonPath('data.receiving_progress.confirmed_grn_count', 0)
            ->assertJsonPath('data.receiving_progress.usable_received_quantity', '0.000')
            ->assertJsonPath('data.status', 'confirmed');
        $this->getJson('/api/v1/orders/'.$order->id.'/receipts')
            ->assertOk()->assertJsonPath('data.0.status', 'pending');
    }

    public function test_confirmed_grn_updates_order_to_partially_received_with_damaged_subtracted(): void
    {
        $order = $this->approvedOrder();
        $this->send($order, supplierConfirm: true);
        $this->receipt($order, '4.000', '1.000');
        app(OrderStatusSync::class)->afterConfirmedGrn($order->purchase_order_id);

        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.receiving_progress.ordered_quantity', '10.000')
            ->assertJsonPath('data.receiving_progress.received_quantity', '4.000')
            ->assertJsonPath('data.receiving_progress.damaged_quantity', '1.000')
            ->assertJsonPath('data.receiving_progress.usable_received_quantity', '3.000')
            ->assertJsonPath('data.receiving_progress.remaining_quantity', '7.000');
        $this->assertSame('3.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_repeated_confirmed_grns_complete_order_exactly(): void
    {
        $order = $this->approvedOrder();
        $this->send($order, supplierConfirm: true);
        $this->receipt($order, '4.000');
        $sync = app(OrderStatusSync::class);
        $sync->afterConfirmedGrn($order->purchase_order_id);
        $this->assertSame('partially_received', $order->fresh()->status);
        $this->receipt($order, '6.000', status: 'fully_received');
        $sync->afterConfirmedGrn($order->purchase_order_id);
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_fully_damaged_confirmed_grn_does_not_add_stock_or_complete_order(): void
    {
        $order = $this->approvedOrder();
        $this->send($order, supplierConfirm: true);
        $this->receipt($order, '2.000', '2.000', status: 'discrepancy');
        app(OrderStatusSync::class)->afterConfirmedGrn($order->purchase_order_id);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_status_sync_is_idempotent_and_does_not_mutate_stock(): void
    {
        $order = $this->approvedOrder();
        $this->send($order, supplierConfirm: true);
        $this->receipt($order, '3.500');
        $sync = app(OrderStatusSync::class);
        $first = $sync->afterConfirmedGrn($order->purchase_order_id);
        $firstUpdated = $first->updated_at?->toIso8601String();
        $second = $sync->afterConfirmedGrn($order->purchase_order_id);
        $this->assertSame('partially_received', $second->status);
        $this->assertSame($firstUpdated, $second->updated_at?->toIso8601String());
        $this->assertSame('3.500', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_sync_rejects_pending_grn(): void
    {
        $order = $this->approvedOrder();
        $this->send($order);
        $this->receipt($order, '1.000', confirmed: false);
        $this->expectException(OrderConflict::class);
        app(OrderStatusSync::class)->afterConfirmedGrn($order->purchase_order_id);
    }

    public function test_sync_rejects_confirmed_grn_without_stock_movement(): void
    {
        $order = $this->approvedOrder();
        $this->send($order);
        $this->receipt($order, '1.000', applyStock: false);
        $this->expectException(OrderConflict::class);
        app(OrderStatusSync::class)->afterConfirmedGrn($order->purchase_order_id);
    }

    public function test_sync_rejects_grn_for_unshipped_po(): void
    {
        $order = $this->approvedOrder();
        $this->expectException(OrderConflict::class);
        app(OrderStatusSync::class)->afterConfirmedGrn($order->purchase_order_id);
    }

    public function test_over_receipt_rolls_back_grn_stock_and_order_together(): void
    {
        $order = $this->approvedOrder();
        $this->send($order);
        try {
            DB::transaction(function () use ($order): void {
                $this->receipt($order, '11.000');
                app(OrderStatusSync::class)->afterConfirmedGrn($order->purchase_order_id);
            });
            $this->fail('Penerimaan melebihi qty PO seharusnya ditolak.');
        } catch (OrderConflict) {
            $this->assertDatabaseCount('goods_receipts', 0);
            $this->assertDatabaseCount('stock_movements', 0);
            $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
            $this->assertSame('pending', $order->fresh()->status);
        }
    }

    public function test_supplier_confirmation_after_partial_receipt_preserves_progress(): void
    {
        $order = $this->approvedOrder();
        $this->send($order);
        $this->receipt($order, '3.000');
        app(OrderStatusSync::class)->afterConfirmedGrn($order->purchase_order_id);
        $this->assertSame('partially_received', $order->fresh()->status);
        $po = PurchaseOrder::query()->findOrFail($order->purchase_order_id);
        app(PurchaseOrderWorkflow::class)->supplierConfirm($po, $this->maker);
        $this->assertSame('received_by_supplier', $po->fresh()->status);
        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->confirmed_at);
    }

    public function test_cancelled_order_is_visible_even_if_po_soft_deleted(): void
    {
        $order = $this->approvedOrder();
        $po = PurchaseOrder::query()->findOrFail($order->purchase_order_id);
        $workflow = app(PurchaseOrderWorkflow::class);
        $workflow->cancel($po, $this->maker, 'Kebutuhan dibatalkan');
        $workflow->softDelete($po, $this->maker);
        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.purchase_order.po_number', $po->po_number);
    }

    public function test_orders_are_read_only_through_public_api(): void
    {
        $order = $this->approvedOrder();
        $this->signIn($this->roleUser(['orders.view']));
        $this->patchJson('/api/v1/orders/'.$order->id, ['status' => 'completed'])
            ->assertStatus(405);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_invalid_status_filter_returns_422(): void
    {
        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders?status=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_unknown_order_returns_404(): void
    {
        $this->signIn($this->roleUser(['orders.view']));
        $this->getJson('/api/v1/orders/999999999')->assertNotFound();
    }
}
