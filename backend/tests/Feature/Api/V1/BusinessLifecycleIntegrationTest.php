<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * E2E lintas modul. Menggunakan HTTP API dan DatabaseTransactions,
 * tidak menjalankan migrate:fresh maupun menyentuh database development.
 */
final class BusinessLifecycleIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private Supplier $supplier;

    private Department $department;

    private Employee $employee;

    private Item $item;

    private User $employeeUser;

    private User $maker;

    private User $hr;

    private User $gm;

    private User $warehouse;

    private int $roleCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach ([
            'po.view', 'po.create', 'po.submit', 'po.send', 'po.supplier-confirm',
            'po.approve.hr', 'po.approve.gm', 'orders.view',
            'grn.view', 'grn.create', 'grn.confirm',
            'distribution.view', 'distribution.create', 'distribution.approve',
            'distribution.reject', 'distribution.prepare', 'distribution.send',
            'inventory.view', 'inventory.adjust', 'reports.view',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->department = Department::query()->create([
            'code' => 'E2E-DEPT', 'name' => 'Integrasi End-to-End', 'is_active' => true,
        ]);
        $this->employeeUser = User::factory()->create(['is_active' => true]);
        $this->employee = Employee::query()->create([
            'department_id' => $this->department->id,
            'user_id' => $this->employeeUser->id,
            'employee_number' => 'E2E-EMP',
            'name' => 'Penerima Barang E2E', 'is_active' => true,
        ]);
        $this->supplier = Supplier::query()->create([
            'code' => 'E2E-SUP', 'name' => 'Distributor Pengujian E2E',
            'normalized_name' => 'distributor pengujian e2e', 'is_active' => true,
        ]);
        $category = Category::query()->create([
            'code' => 'E2E-CAT', 'name' => 'Barang E2E', 'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'E2E-SKU',
            'name' => 'Barang Uji E2E', 'unit' => 'pcs',
            'minimum_stock' => '2.000', 'is_active' => true,
        ]);

        $this->maker = $this->actor([
            'po.view', 'po.create', 'po.submit', 'po.send', 'po.supplier-confirm',
        ]);
        $this->hr = $this->actor(['po.approve.hr', 'grn.confirm']);
        $this->gm = $this->actor(['po.approve.gm', 'distribution.approve', 'distribution.reject']);
        $this->warehouse = $this->actor([
            'grn.view', 'grn.create', 'distribution.view', 'distribution.create',
            'distribution.prepare', 'distribution.send', 'inventory.adjust', 'inventory.view',
        ]);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): User
    {
        $role = Role::findOrCreate('e2e_quality_role_'.(++$this->roleCounter), 'web');
        $role->syncPermissions($permissions);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole($role);

        return $actor;
    }

    private function signIn(User $actor, array $abilities = ['*']): void
    {
        Sanctum::actingAs($actor, $abilities);
    }

    private function poPayload(string $unitPrice = '100000.00', string $qty = '10.000'): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id,
            'order_date' => '2026-11-04',
            'expected_date' => '2026-11-20',
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
            ]],
            'tax_rate_ids' => [],
        ];
    }

    private function createPo(string $unitPrice = '100000.00', string $qty = '10.000'): int
    {
        $this->signIn($this->maker);

        return (int) $this->postJson(
            '/api/v1/purchase-orders', $this->poPayload($unitPrice, $qty)
        )->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    private function approvePo(int $poId, bool $needsGm = false): void
    {
        $this->signIn($this->maker);
        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();
        $this->signIn($this->hr);
        $this->postJson("/api/v1/purchase-orders/{$poId}/approvals/hr/approve")->assertOk();

        if ($needsGm) {
            $this->signIn($this->gm);
            $this->postJson("/api/v1/purchase-orders/{$poId}/approvals/gm/approve")->assertOk();
        }
    }

    private function sendPo(int $poId): void
    {
        $this->signIn($this->maker);
        $this->postJson("/api/v1/purchase-orders/{$poId}/send")->assertOk();
    }

    private function grnPayload(int $poId, string $received, string $damaged = '0.000'): array
    {
        $poItemId = (int) DB::table('purchase_order_items')
            ->where('purchase_order_id', $poId)->value('id');

        return [
            'purchase_order_id' => $poId,
            'received_date' => '2026-11-04',
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'qty_received' => $received,
                'qty_damaged' => $damaged,
            ]],
        ];
    }

    private function createGrn(int $poId, string $received, string $damaged = '0.000'): int
    {
        $this->signIn($this->warehouse);

        return (int) $this->postJson('/api/v1/goods-receipts',
            $this->grnPayload($poId, $received, $damaged)
        )->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    }

    private function confirmGrn(int $grnId): void
    {
        $this->signIn($this->hr);
        $this->postJson("/api/v1/goods-receipts/{$grnId}/confirm")->assertOk();
    }

    private function distribute(string $quantity = '3.000'): int
    {
        $this->signIn($this->warehouse);

        return (int) $this->postJson('/api/v1/distributions', [
            'employee_id' => $this->employee->id,
            'purpose' => 'Pemakaian perlengkapan kerja hasil pengadaan',
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => $quantity,
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');
    }

    private function prepareDistribution(int $id): void
    {
        $this->signIn($this->gm);
        $this->postJson("/api/v1/distributions/{$id}/approve")->assertOk();
        $this->signIn($this->warehouse);
        $this->postJson("/api/v1/distributions/{$id}/prepare")->assertOk();
    }

    private function stock(): string
    {
        return (string) $this->item->fresh()->stock_quantity;
    }

    private function movements(string $type): int
    {
        return DB::table('stock_movements')
            ->where('item_id', $this->item->id)
            ->where('movement_type', $type)
            ->count();
    }

    public function test_full_journey_po_partial_damaged_grn_order_and_employee_distribution(): void
    {
        $poId = $this->createPo();
        $this->assertSame('0.000', $this->stock());
        $this->approvePo($poId);
        $this->assertSame(1, DB::table('orders')->where('purchase_order_id', $poId)->count());
        $this->assertSame('pending', DB::table('orders')->where('purchase_order_id', $poId)->value('status'));

        $this->sendPo($poId);
        $grn1 = $this->createGrn($poId, '5.000', '1.000');
        $this->assertSame('0.000', $this->stock());
        $this->assertSame(0, $this->movements('grn_in'));
        $this->confirmGrn($grn1);
        $this->assertSame('discrepancy', DB::table('goods_receipts')->where('id', $grn1)->value('status'));
        $this->assertSame('4.000', $this->stock());
        $this->assertSame('partially_received', DB::table('orders')->where('purchase_order_id', $poId)->value('status'));

        // Supplier dapat mengonfirmasi sesudah sebagian barang datang,
        // tetapi status Order tidak boleh kembali ke Confirmed.
        $this->signIn($this->maker);
        $this->postJson("/api/v1/purchase-orders/{$poId}/supplier-confirm")->assertOk();
        $this->assertSame('partially_received', DB::table('orders')->where('purchase_order_id', $poId)->value('status'));

        $grn2 = $this->createGrn($poId, '6.000');
        $this->confirmGrn($grn2);
        $this->assertSame('10.000', $this->stock());
        $this->assertSame(2, $this->movements('grn_in'));
        $this->assertSame('completed', DB::table('orders')->where('purchase_order_id', $poId)->value('status'));

        $distributionId = $this->distribute();
        $this->prepareDistribution($distributionId);
        $this->assertSame('10.000', $this->stock());
        $this->signIn($this->warehouse);
        $this->postJson("/api/v1/distributions/{$distributionId}/send")
            ->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertSame('7.000', $this->stock());
        $this->assertSame(1, $this->movements('distribution_out'));

        $this->signIn($this->employeeUser, ['employee:self']);
        $this->postJson("/api/v1/distributions/{$distributionId}/receive")
            ->assertOk()->assertJsonPath('data.status', 'received_by_employee');
        $this->assertSame('7.000', $this->stock());
        $this->assertSame(1, $this->movements('distribution_out'));
        $this->assertSame(1, DB::table('purchase_order_events')
            ->where('purchase_order_id', $poId)->where('action', 'fully_approved')->count());
        $this->assertSame(1, DB::table('distribution_events')
            ->where('distribution_id', $distributionId)->where('action', 'received')->count());
    }

    public function test_above_threshold_requires_hr_then_gm_and_only_one_order(): void
    {
        $poId = $this->createPo('7000000.01', '1.000');
        $this->signIn($this->maker);
        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();
        $this->signIn($this->gm);
        $this->postJson("/api/v1/purchase-orders/{$poId}/approvals/gm/approve")->assertStatus(409);
        $this->signIn($this->hr);
        $this->postJson("/api/v1/purchase-orders/{$poId}/approvals/hr/approve")->assertOk();
        $this->assertSame('pending_approval', PurchaseOrder::query()->findOrFail($poId)->status);
        $this->assertSame(0, DB::table('orders')->where('purchase_order_id', $poId)->count());
        $this->signIn($this->gm);
        $endpoint = "/api/v1/purchase-orders/{$poId}/approvals/gm/approve";
        $this->postJson($endpoint)->assertOk();
        $this->postJson($endpoint)->assertOk();
        $this->assertSame(1, DB::table('orders')->where('purchase_order_id', $poId)->count());
    }

    public function test_po_approval_and_send_do_not_touch_stock(): void
    {
        $poId = $this->createPo();
        $this->approvePo($poId);
        $this->sendPo($poId);
        $this->signIn($this->maker);
        $this->postJson("/api/v1/purchase-orders/{$poId}/supplier-confirm")->assertOk();
        $this->assertSame('0.000', $this->stock());
        $this->assertSame(0, DB::table('stock_movements')->where('item_id', $this->item->id)->count());
    }

    public function test_grn_pending_and_duplicate_confirmation_never_double_mutate(): void
    {
        $poId = $this->createPo();
        $this->approvePo($poId);
        $this->sendPo($poId);
        $grnId = $this->createGrn($poId, '10.000');
        $this->assertSame('0.000', $this->stock());
        $this->confirmGrn($grnId);
        $this->confirmGrn($grnId);
        $this->assertSame('10.000', $this->stock());
        $this->assertSame(1, $this->movements('grn_in'));
        $this->assertSame('completed', DB::table('orders')->where('purchase_order_id', $poId)->value('status'));
    }

    public function test_second_grn_over_receipt_rolls_back_order_and_stock(): void
    {
        $poId = $this->createPo();
        $this->approvePo($poId);
        $this->sendPo($poId);
        $grn1 = $this->createGrn($poId, '9.000');
        $grn2 = $this->createGrn($poId, '2.000');
        $this->confirmGrn($grn1);
        $this->signIn($this->hr);
        $this->postJson("/api/v1/goods-receipts/{$grn2}/confirm")->assertStatus(409);
        $this->assertSame('pending', DB::table('goods_receipts')->where('id', $grn2)->value('status'));
        $this->assertSame('9.000', $this->stock());
        $this->assertSame(1, $this->movements('grn_in'));
        $this->assertSame('partially_received', DB::table('orders')->where('purchase_order_id', $poId)->value('status'));
    }

    public function test_insufficient_stock_rejects_sent_and_rolls_back(): void
    {
        $distributionId = $this->distribute('3.000');
        $this->prepareDistribution($distributionId);
        $this->signIn($this->warehouse);
        $this->postJson("/api/v1/distributions/{$distributionId}/send")->assertStatus(409);
        $this->assertSame('preparing', DB::table('distributions')->where('id', $distributionId)->value('status'));
        $this->assertSame('0.000', $this->stock());
        $this->assertSame(0, $this->movements('distribution_out'));
        $this->assertSame(0, DB::table('distribution_events')
            ->where('distribution_id', $distributionId)->where('action', 'sent')->count());
    }

    public function test_adjustment_replay_and_stale_balance_have_separate_audit(): void
    {
        $this->signIn($this->warehouse);
        $url = "/api/v1/items/{$this->item->id}/stock-adjustments";
        $key = (string) Str::uuid();
        $body = [
            'expected_quantity' => '0.000',
            'target_quantity' => '4.000',
            'reason' => 'Opname integrasi lintas modul',
        ];
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $body)->assertCreated();
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $body)->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, $body)->assertStatus(409);
        $this->assertSame('4.000', $this->stock());
        $this->assertSame(1, $this->movements('adjustment'));
    }

    public function test_sent_and_receive_replay_do_not_change_stock_twice(): void
    {
        $this->signIn($this->warehouse);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/items/{$this->item->id}/stock-adjustments", [
                'expected_quantity' => '0.000',
                'target_quantity' => '5.000',
                'reason' => 'Stock opname untuk replay distribusi',
            ])->assertCreated();

        $distributionId = $this->distribute('3.000');
        $this->prepareDistribution($distributionId);
        $this->signIn($this->warehouse);
        $url = "/api/v1/distributions/{$distributionId}/send";
        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk();
        $this->signIn($this->employeeUser, ['employee:self']);
        $receiptUrl = "/api/v1/distributions/{$distributionId}/receive";
        $this->postJson($receiptUrl)->assertOk();
        $this->postJson($receiptUrl)->assertOk();
        $this->assertSame('2.000', $this->stock());
        $this->assertSame(1, $this->movements('distribution_out'));
    }

    public function test_grn_cannot_be_created_until_po_is_sent(): void
    {
        $poId = $this->createPo();
        $this->signIn($this->warehouse);
        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($poId, '1.000'))
            ->assertStatus(409);
        $this->assertSame(0, DB::table('goods_receipts')->where('purchase_order_id', $poId)->count());
        $this->assertSame('0.000', $this->stock());
    }
}
