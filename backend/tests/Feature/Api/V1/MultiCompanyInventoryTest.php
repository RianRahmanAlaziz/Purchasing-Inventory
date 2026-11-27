<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Company\Models\Company;
use App\Domain\Distribution\Models\Distribution;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domain\Purchasing\Actions\SaveDraftPurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Receiving\Actions\ConfirmGoodsReceipt;
use App\Domain\Receiving\Actions\SaveGoodsReceipt;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Transfer\Actions\TransferWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MultiCompanyInventoryTest extends TestCase
{
    use DatabaseTransactions;

    private Company $ga;

    private Company $k35;

    private Item $item;

    private User $operator;

    private int $roleSerial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach (['inventory.view', 'inventory.adjust', 'inventory.reconcile', 'items.view', 'po.approve.hr', 'distribution.send'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ga = Company::query()->create(['name' => 'PT GA Pengujian', 'address' => 'Alamat GA']);
        $this->k35 = Company::query()->create(['name' => 'PT K35 Pengujian', 'address' => 'Alamat K35']);
        $category = Category::query()->create(['code' => 'MC-CAT', 'name' => 'Barang Multi PT', 'is_active' => true]);
        $this->item = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'MC-001', 'name' => 'Kertas Multi PT',
            'unit' => 'rim', 'item_type' => 'consumable', 'is_active' => true,
        ]);
        $this->operator = $this->actor(['inventory.view', 'inventory.adjust', 'inventory.reconcile', 'items.view']);
        Sanctum::actingAs($this->operator, ['*']);
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actor(array $permissions): User
    {
        $role = Role::findOrCreate('multi_company_test_'.(++$this->roleSerial), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function quantity(?Company $company): string
    {
        return (string) (DB::table('inventory_balances')->where('item_id', $this->item->id)
            ->where('location_key', $company?->id ?? 0)->value('quantity') ?? '0.000');
    }

    private function adjust(Company $company, string $expected, string $target, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/items/'.$this->item->id.'/stock-adjustments', [
                'company_id' => $company->id, 'expected_quantity' => $expected,
                'target_quantity' => $target, 'reason' => 'Hasil stock opname per PT',
            ]);
    }

    private function legacyStock(string $quantity = '10.000'): void
    {
        app(StockService::class)->adjustTo(
            (int) $this->item->id, '0.000', $quantity, 'Saldo lama belum diketahui PT',
            (int) $this->operator->id, (string) Str::uuid(),
        );
    }

    private function reconcilePayload(string $quantity = '3.000'): array
    {
        return [
            'item_id' => $this->item->id, 'company_id' => $this->k35->id,
            'quantity' => $quantity, 'reason' => 'Rekonsiliasi berdasarkan pemeriksaan fisik',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function test_adjustment_is_scoped_to_pt_and_total_is_a_projection(): void
    {
        $this->adjust($this->ga, '0.000', '10.000')->assertCreated();
        $this->adjust($this->k35, '0.000', '3.250')->assertCreated();

        $this->assertSame('10.000', $this->quantity($this->ga));
        $this->assertSame('3.250', $this->quantity($this->k35));
        $this->assertSame('13.250', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->item->id, 'company_id' => $this->k35->id,
            'quantity_change' => '3.250', 'balance_before' => '0.000', 'balance_after' => '3.250',
        ]);
        $this->getJson('/api/v1/items?search=MC-001&company_id='.$this->k35->id)->assertOk()
            ->assertJsonPath('data.0.stock_quantity', '3.250')
            ->assertJsonPath('data.0.total_stock_quantity', '13.250');
    }

    public function test_adjustment_replay_cannot_be_reused_for_a_different_pt(): void
    {
        $key = (string) Str::uuid();
        $first = $this->adjust($this->ga, '0.000', '5.000', $key)->assertCreated();
        $this->adjust($this->ga, '0.000', '5.000', $key)->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->adjust($this->k35, '0.000', '5.000', $key)->assertStatus(409);

        $this->assertSame('5.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame('0.000', $this->quantity($this->k35));
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $this->item->id)->count());
    }

    public function test_adjustment_compares_expected_balance_with_selected_pt_not_global_total(): void
    {
        $this->adjust($this->ga, '0.000', '8.000')->assertCreated();
        $this->adjust($this->k35, '8.000', '2.000')->assertStatus(409);

        $this->assertSame('8.000', $this->quantity($this->ga));
        $this->assertSame('0.000', $this->quantity($this->k35));
        $this->assertSame('8.000', (string) $this->item->fresh()->stock_quantity);
    }

    public function test_legacy_stock_reconciliation_preserves_total_and_replays_once(): void
    {
        $this->legacyStock();
        $companyCount = Company::query()->count();
        $movementCount = DB::table('stock_movements')->where('item_id', $this->item->id)->count();
        $payload = $this->reconcilePayload();
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/inventory/reconcile', $payload)->assertSuccessful();
        }

        $this->assertSame('7.000', $this->quantity(null));
        $this->assertSame('3.000', $this->quantity($this->k35));
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame($companyCount, Company::query()->count());
        $this->assertSame($movementCount + 2, DB::table('stock_movements')->where('item_id', $this->item->id)->count());
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->item->id, 'company_id' => null, 'quantity_change' => '-3.000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->item->id, 'company_id' => $this->k35->id, 'quantity_change' => '3.000',
        ]);
    }

    public function test_reconciliation_cannot_allocate_more_than_unallocated_stock(): void
    {
        $this->legacyStock('2.000');
        $movementCount = DB::table('stock_movements')->where('item_id', $this->item->id)->count();
        $this->postJson('/api/v1/inventory/reconcile', $this->reconcilePayload('3.000'))->assertStatus(409);

        $this->assertSame('2.000', $this->quantity(null));
        $this->assertSame('0.000', $this->quantity($this->k35));
        $this->assertSame('2.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame($movementCount, DB::table('stock_movements')->where('item_id', $this->item->id)->count());
    }

    public function test_viewer_without_reconciliation_permission_gets_403(): void
    {
        Sanctum::actingAs($this->actor(['inventory.view']), ['*']);
        $this->postJson('/api/v1/inventory/reconcile', $this->reconcilePayload())->assertForbidden();
        $this->getJson('/api/v1/inventory/overview')->assertOk();

        Sanctum::actingAs($this->actor([]), ['*']);
        $this->getJson('/api/v1/inventory/overview')->assertForbidden();
    }

    public function test_operational_company_lookup_uses_existing_active_master_pt(): void
    {
        $deleted = Company::query()->create(['name' => 'PT Dihapus', 'address' => 'Alamat lama']);
        $deleted->delete();
        $this->getJson('/api/v1/inventory/companies')->assertOk()
            ->assertJsonFragment(['id' => $this->ga->id, 'name' => $this->ga->name])
            ->assertJsonFragment(['id' => $this->k35->id, 'name' => $this->k35->name])
            ->assertJsonMissing(['id' => $deleted->id, 'name' => $deleted->name]);
    }

    public function test_inventory_overview_keeps_controlled_total_while_transfer_is_in_transit(): void
    {
        $this->operator->givePermissionTo(['inventory.transfer.create', 'inventory.transfer.send', 'inventory.transfer.receive']);
        $this->adjust($this->ga, '0.000', '10.000')->assertCreated();
        $workflow = app(TransferWorkflow::class);
        $transfer = $workflow->save([
            'from_company_id' => $this->ga->id, 'to_company_id' => $this->k35->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => '3.000']],
        ], $this->operator);
        $workflow->send($transfer, $this->operator);

        $this->getJson('/api/v1/inventory/overview')->assertOk()
            ->assertJsonPath('data.totals.available', '7.000')
            ->assertJsonPath('data.totals.in_transit', '3.000')
            ->assertJsonPath('data.totals.controlled_quantity', '10.000')
            ->assertJsonPath('data.totals.assets_in_use', 0)
            ->assertJsonPath('data.totals.assets_damaged', 0);

        $workflow->receive($transfer, $this->operator);
        $this->getJson('/api/v1/inventory/overview')->assertOk()
            ->assertJsonPath('data.totals.available', '10.000')
            ->assertJsonPath('data.totals.in_transit', fn (mixed $value): bool => StockDecimal::parse((string) $value) === 0)
            ->assertJsonPath('data.totals.controlled_quantity', '10.000');
    }

    private function legacyDistribution(): Distribution
    {
        $distribution = Distribution::query()->create([
            'distribution_number' => 'MC-LEGACY-'.Str::ulid(), 'company_id' => null,
            'recipient_name_snapshot' => 'Penerima Distribusi Lama', 'requested_by' => $this->operator->id,
            'purpose' => 'Pemakaian perlengkapan kerja', 'status' => 'preparing', 'requested_at' => now(),
        ]);
        $distribution->items()->create(['item_id' => $this->item->id, 'quantity' => '3.000']);

        return $distribution;
    }

    public function test_legacy_distribution_selects_source_once_then_completes_without_relocation(): void
    {
        $this->operator->givePermissionTo('distribution.send');
        $this->adjust($this->ga, '0.000', '5.000')->assertCreated();
        $distribution = $this->legacyDistribution();
        $url = '/api/v1/distributions/'.$distribution->id;

        foreach ([1, 2] as $attempt) {
            $this->postJson($url.'/location', ['company_id' => $this->ga->id])->assertOk()
                ->assertJsonPath('data.company_id', $this->ga->id);
        }
        $this->assertSame(1, $distribution->events()->where('action', 'location_selected')->count());
        $this->assertSame('5.000', $this->quantity($this->ga));
        $this->postJson($url.'/send')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson($url.'/location', ['company_id' => $this->k35->id])->assertStatus(409);

        $this->assertSame((int) $this->ga->id, (int) $distribution->fresh()->company_id);
        $this->assertSame('2.000', $this->quantity($this->ga));
        $this->assertSame('0.000', $this->quantity($this->k35));
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $this->item->id)->where('movement_type', 'distribution_out')->count());
    }

    public function test_legacy_distribution_location_requires_send_permission(): void
    {
        $distribution = $this->legacyDistribution();
        $this->postJson('/api/v1/distributions/'.$distribution->id.'/location', ['company_id' => $this->ga->id])
            ->assertForbidden();
        $this->assertNull($distribution->fresh()->company_id);
        $this->assertSame(0, $distribution->events()->count());
    }

    private function sentPo(): PurchaseOrder
    {
        $supplier = Supplier::query()->create([
            'code' => 'MC-SUP', 'name' => 'Supplier Multi PT', 'normalized_name' => 'supplier multi pt', 'is_active' => true,
        ]);
        $po = app(SaveDraftPurchaseOrder::class)->create([
            'company_id' => $this->ga->id, 'supplier_id' => $supplier->id,
            'requested_for_employee_name' => 'Pemohon Multi PT',
            'requested_for_department_name' => 'Operasional', 'order_date' => '2026-11-26',
            'items' => [['item_id' => $this->item->id, 'quantity' => '10.000', 'unit_price' => '10000.00']],
            'tax_rate_ids' => [],
        ], (int) $this->operator->id);
        $hr = $this->actor(['po.approve.hr']);
        $hr->assignRole(Role::findOrCreate('hr', 'web'));
        $workflow = app(PurchaseOrderWorkflow::class);
        $workflow->submit($po, $this->operator);
        $workflow->approve($po, $hr, 'hr');
        $workflow->send($po, $this->operator);

        return $po->fresh();
    }

    public function test_confirmed_grn_defaults_to_po_pt_and_explicit_destination_override_is_idempotent(): void
    {
        $po = $this->sentPo();
        $lineId = $po->items()->sole()->id;
        $save = app(SaveGoodsReceipt::class);
        $confirm = app(ConfirmGoodsReceipt::class);
        $base = ['purchase_order_id' => $po->id, 'received_date' => '2026-11-26'];
        $first = $save->create($base + ['items' => [[
            'purchase_order_item_id' => $lineId, 'qty_received' => '4.000', 'qty_damaged' => '1.000',
        ]]], (int) $this->operator->id);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $confirm->execute($first, (int) $this->operator->id);
        $this->assertSame('3.000', $this->quantity($this->ga));
        $this->assertSame((int) $this->ga->id, (int) $first->fresh()->company_id);

        $second = $save->create($base + ['company_id' => $this->k35->id, 'items' => [[
            'purchase_order_item_id' => $lineId, 'qty_received' => '7.000', 'qty_damaged' => '0.000',
        ]]], (int) $this->operator->id);
        foreach ([1, 2] as $attempt) {
            $confirm->execute($second, (int) $this->operator->id);
        }
        $this->assertSame('3.000', $this->quantity($this->ga));
        $this->assertSame('7.000', $this->quantity($this->k35));
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(2, DB::table('stock_movements')->where('item_id', $this->item->id)->where('movement_type', 'grn_in')->count());
        $this->assertDatabaseHas('stock_movements', [
            'goods_receipt_item_id' => $second->items()->sole()->id,
            'company_id' => $this->k35->id, 'quantity_change' => '7.000',
        ]);
        $this->assertSame((int) $this->ga->id, (int) $po->fresh()->company_id);
    }

    public function test_historical_confirmed_grn_without_pt_replays_without_guessing_a_location_or_adding_stock(): void
    {
        $po = $this->sentPo();
        $receipt = app(SaveGoodsReceipt::class)->create([
            'purchase_order_id' => $po->id, 'received_date' => '2026-11-26',
            'items' => [['purchase_order_item_id' => $po->items()->sole()->id, 'qty_received' => '2.000', 'qty_damaged' => '0.000']],
        ], (int) $this->operator->id);
        $confirm = app(ConfirmGoodsReceipt::class);
        $confirm->execute($receipt, (int) $this->operator->id);
        DB::table('goods_receipts')->where('id', $receipt->id)->update(['company_id' => null]);
        $before = DB::table('stock_movements')->where('item_id', $this->item->id)->count();

        $replayed = $confirm->execute($receipt->fresh(), (int) $this->operator->id);
        $this->assertNull($replayed->company_id);
        $this->assertSame('2.000', $this->quantity($this->ga));
        $this->assertSame('0.000', $this->quantity($this->k35));
        $this->assertSame($before, DB::table('stock_movements')->where('item_id', $this->item->id)->count());
    }
}
