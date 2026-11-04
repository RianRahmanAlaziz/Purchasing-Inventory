<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domain\Purchasing\Actions\SaveDraftPurchaseOrder;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Receiving\Actions\SaveGoodsReceipt;
use App\Domain\Receiving\Models\GoodsReceipt;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GoodsReceiptApiTest extends TestCase
{
    use DatabaseTransactions;

    private Department $department;

    private Employee $employee;

    private Supplier $supplier;

    private Item $item;

    private User $maker;

    private User $hr;

    private int $roleIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach (['grn.view', 'grn.create', 'grn.confirm', 'po.approve.hr'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->department = Department::query()->create([
            'code' => 'GRN-DEPT', 'name' => 'GRN Department', 'is_active' => true,
        ]);
        $this->employee = Employee::query()->create([
            'employee_number' => 'GRN-EMP-001', 'department_id' => $this->department->id,
            'name' => 'Pemohon GRN', 'is_active' => true,
        ]);
        $this->supplier = Supplier::query()->create([
            'code' => 'GRN-SUP', 'name' => 'Supplier GRN',
            'normalized_name' => 'supplier grn', 'is_active' => true,
        ]);
        $category = Category::query()->create([
            'code' => 'GRN-CAT', 'name' => 'Kategori GRN', 'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'GRN-SKU-001',
            'name' => 'Barang GRN', 'unit' => 'pcs',
            'minimum_stock' => '2.000', 'is_active' => true,
        ]);
        $this->maker = User::factory()->create(['is_active' => true]);
        $this->hr = $this->userWith(['po.approve.hr']);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('dynamic_grn_role_'.(++$this->roleIndex), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function po(string $status = 'sent'): PurchaseOrder
    {
        $po = app(SaveDraftPurchaseOrder::class)->create([
            'supplier_id' => $this->supplier->id,
            'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id,
            'order_date' => '2026-11-04',
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => '10.000',
                'unit_price' => '50000.00',
            ]],
            'tax_rate_ids' => [],
        ], (int) $this->maker->id);

        if ($status === 'draft') {
            return $po;
        }

        $flow = app(PurchaseOrderWorkflow::class);
        $flow->submit($po, $this->maker);
        $flow->approve($po, $this->hr, 'hr');
        if ($status === 'approved') {
            return $po->fresh();
        }
        $flow->send($po, $this->maker);
        if ($status === 'received_by_supplier') {
            $flow->supplierConfirm($po, $this->maker);
        }

        return $po->fresh();
    }

    private function poItemId(PurchaseOrder $po): int
    {
        return (int) DB::table('purchase_order_items')
            ->where('purchase_order_id', $po->id)->value('id');
    }

    private function payload(
        PurchaseOrder $po,
        string $received = '4.000',
        string $damaged = '0.000',
    ): array {
        return [
            'purchase_order_id' => $po->id,
            'received_date' => '2026-11-04',
            'notes' => 'Pemeriksaan fisik barang',
            'items' => [[
                'purchase_order_item_id' => $this->poItemId($po),
                'qty_received' => $received,
                'qty_damaged' => $damaged,
            ]],
        ];
    }

    private function pending(PurchaseOrder $po, string $received = '4.000', string $damaged = '0.000'): GoodsReceipt
    {
        return app(SaveGoodsReceipt::class)->create(
            $this->payload($po, $received, $damaged), (int) $this->maker->id
        );
    }

    private function confirmUrl(GoodsReceipt $grn): string
    {
        return '/api/v1/goods-receipts/'.$grn->id.'/confirm';
    }

    public function test_guest_cannot_list_grns(): void
    {
        $this->getJson('/api/v1/goods-receipts')->assertUnauthorized();
    }

    public function test_dynamic_viewer_can_read_but_cannot_create_or_confirm(): void
    {
        $po = $this->po();
        $grn = $this->pending($po);
        $this->signIn($this->userWith(['grn.view']));
        $this->getJson('/api/v1/goods-receipts/'.$grn->id)->assertOk();
        $this->postJson('/api/v1/goods-receipts', $this->payload($po))->assertForbidden();
        $this->postJson($this->confirmUrl($grn))->assertForbidden();
    }

    public function test_grn_creator_without_confirm_permission_cannot_confirm(): void
    {
        $grn = $this->pending($this->po());
        $this->signIn($this->userWith(['grn.create']));
        $this->postJson($this->confirmUrl($grn))->assertForbidden();
    }

    public function test_creating_pending_grn_does_not_mutate_stock_or_order(): void
    {
        $po = $this->po();
        $this->signIn($this->userWith(['grn.create']));
        $this->postJson('/api/v1/goods-receipts', $this->payload($po))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items.0.qty_received', '4.000');
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('pending', Order::query()->where('purchase_order_id', $po->id)->value('status'));
    }

    public function test_grn_created_by_backend_has_generated_number_and_actor(): void
    {
        $po = $this->po();
        $actor = $this->userWith(['grn.create']);
        $this->signIn($actor);
        $result = $this->postJson('/api/v1/goods-receipts', $this->payload($po))
            ->assertCreated();
        $this->assertStringStartsWith('GRN-', $result->json('data.grn_number'));
        $this->assertSame($actor->id, $result->json('data.created_by'));
    }

    public function test_cannot_create_grn_from_draft_or_approved_po(): void
    {
        $this->signIn($this->userWith(['grn.create']));
        foreach (['draft', 'approved'] as $status) {
            $po = $this->po($status);
            $this->postJson('/api/v1/goods-receipts', $this->payload($po))
                ->assertStatus(409);
        }
    }

    public function test_grn_allowed_from_po_received_by_supplier(): void
    {
        $po = $this->po('received_by_supplier');
        $this->signIn($this->userWith(['grn.create']));
        $this->postJson('/api/v1/goods-receipts', $this->payload($po))->assertCreated();
    }

    public function test_cannot_receive_item_from_other_po(): void
    {
        $po1 = $this->po();
        $po2 = $this->po();
        $data = $this->payload($po1);
        $data['items'][0]['purchase_order_item_id'] = $this->poItemId($po2);
        $this->signIn($this->userWith(['grn.create']));
        $this->postJson('/api/v1/goods-receipts', $data)->assertStatus(409);
    }

    public function test_repeated_po_line_is_rejected_by_request_validation(): void
    {
        $po = $this->po();
        $data = $this->payload($po);
        $data['items'][] = $data['items'][0];
        $this->signIn($this->userWith(['grn.create']));
        $this->postJson('/api/v1/goods-receipts', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.purchase_order_item_id');
    }

    public function test_damaged_cannot_exceed_received(): void
    {
        $po = $this->po();
        $this->signIn($this->userWith(['grn.create']));
        $this->postJson('/api/v1/goods-receipts', $this->payload($po, '2.000', '3.000'))
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.qty_damaged');
    }

    public function test_grn_cannot_accept_zero_quantity_or_decimal_more_than_three_places(): void
    {
        $po = $this->po();
        $this->signIn($this->userWith(['grn.create']));
        $this->postJson('/api/v1/goods-receipts', $this->payload($po, '0.000'))
            ->assertUnprocessable();
        $this->postJson('/api/v1/goods-receipts', $this->payload($po, '1.0009'))
            ->assertUnprocessable();
    }

    public function test_update_pending_grn_does_not_affect_inventory(): void
    {
        $grn = $this->pending($this->po());
        $this->signIn($this->userWith(['grn.create']));
        $this->patchJson('/api/v1/goods-receipts/'.$grn->id, [
            'received_date' => '2026-11-05',
            'items' => [[
                'purchase_order_item_id' => $grn->items->first()->purchase_order_item_id,
                'qty_received' => '6.000', 'qty_damaged' => '1.000',
            ]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items.0.qty_received', '6.000');
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_partial_confirmation_increases_only_usable_stock_and_order_status(): void
    {
        $po = $this->po();
        $grn = $this->pending($po, '4.000');
        $actor = $this->userWith(['grn.confirm']);
        $this->signIn($actor);
        $this->postJson($this->confirmUrl($grn))
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.confirmed_by', $actor->id)
            ->assertJsonPath('data.items.0.qty_received', '4.000');
        $this->assertSame('4.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame('partially_received', Order::query()
            ->where('purchase_order_id', $po->id)->value('status'));
        $this->assertDatabaseHas('stock_movements', [
            'goods_receipt_item_id' => $grn->items->first()->id,
            'movement_type' => 'grn_in',
            'quantity_change' => '4.000',
        ]);
        $this->assertSame('sent', $po->fresh()->status);
    }

    public function test_supplier_confirmation_after_partial_receipt_does_not_downgrade_order(): void
    {
        $po = $this->po();
        $grn = $this->pending($po);
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($grn))->assertOk();
        app(PurchaseOrderWorkflow::class)->supplierConfirm($po, $this->maker);
        $this->assertSame('partially_received', Order::query()
            ->where('purchase_order_id', $po->id)->value('status'));
    }

    public function test_multiple_confirmed_grns_complete_order_without_double_count(): void
    {
        $po = $this->po();
        $grn1 = $this->pending($po, '4.000');
        $grn2 = $this->pending($po, '6.000');
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($grn1))->assertOk();
        $this->postJson($this->confirmUrl($grn2))
            ->assertOk()->assertJsonPath('data.status', 'fully_received');
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame('completed', Order::query()
            ->where('purchase_order_id', $po->id)->value('status'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_damaged_receipt_has_discrepancy_and_only_net_quantity_enters_stock(): void
    {
        $po = $this->po();
        $grn = $this->pending($po, '5.000', '2.000');
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($grn))
            ->assertOk()->assertJsonPath('data.status', 'discrepancy');
        $this->assertSame('3.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame('partially_received', Order::query()
            ->where('purchase_order_id', $po->id)->value('status'));
        $this->assertDatabaseHas('stock_movements', ['quantity_change' => '3.000']);
    }

    public function test_all_damaged_grn_does_not_create_stock_movement(): void
    {
        $po = $this->po();
        $grn = $this->pending($po, '4.000', '4.000');
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($grn))
            ->assertOk()->assertJsonPath('data.status', 'discrepancy');
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame('pending', Order::query()
            ->where('purchase_order_id', $po->id)->value('status'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_confirm_replay_does_not_create_duplicate_stock_movements(): void
    {
        $po = $this->po();
        $grn = $this->pending($po, '10.000');
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($grn))->assertOk();
        $this->postJson($this->confirmUrl($grn))->assertOk();
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(1, Order::query()->where('purchase_order_id', $po->id)->count());
    }

    public function test_confirmed_grn_cannot_be_modified(): void
    {
        $grn = $this->pending($this->po());
        $this->signIn($this->userWith(['grn.create', 'grn.confirm']));
        $this->postJson($this->confirmUrl($grn))->assertOk();
        $this->patchJson('/api/v1/goods-receipts/'.$grn->id, ['notes' => 'Diubah'])
            ->assertStatus(409);
    }

    public function test_over_receipt_rolls_back_second_confirmation(): void
    {
        $po = $this->po();
        $first = $this->pending($po, '7.000');
        $second = $this->pending($po, '4.000');
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($first))->assertOk();
        $this->postJson($this->confirmUrl($second))->assertStatus(409);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertNull($second->fresh()->confirmed_at);
        $this->assertSame('7.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_stock_overflow_rolls_back_grn_and_order_transition(): void
    {
        $po = $this->po();
        $grn = $this->pending($po, '1.000');
        DB::table('items')->where('id', $this->item->id)
            ->update(['stock_quantity' => '999999999999999.999']);
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($grn))->assertStatus(409);
        $this->assertSame('pending', $grn->fresh()->status);
        $this->assertNull($grn->fresh()->confirmed_at);
        $this->assertSame('pending', Order::query()
            ->where('purchase_order_id', $po->id)->value('status'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_grn_confirmation_updates_last_purchase_price_from_po(): void
    {
        $grn = $this->pending($this->po(), '3.000');
        $this->signIn($this->userWith(['grn.confirm']));
        $this->postJson($this->confirmUrl($grn))->assertOk();
        $this->assertSame('50000.00', (string) $this->item->fresh()->last_purchase_price);
    }

    public function test_list_filters_by_po_and_status_with_pagination(): void
    {
        $po1 = $this->po();
        $po2 = $this->po();
        $first = $this->pending($po1);
        $this->pending($po2);
        $this->signIn($this->userWith(['grn.view']));
        $this->getJson('/api/v1/goods-receipts?purchase_order_id='.$po1->id.'&status=pending&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_unknown_grn_returns_404_with_authorized_user(): void
    {
        $this->signIn($this->userWith(['grn.view']));
        $this->getJson('/api/v1/goods-receipts/999999999')->assertNotFound();
    }
}
