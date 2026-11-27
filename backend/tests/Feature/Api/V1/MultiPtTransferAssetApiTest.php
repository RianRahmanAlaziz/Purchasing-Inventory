<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Asset\Actions\AssetWorkflow;
use App\Domain\Asset\Models\OfficeAsset;
use App\Domain\Category\Models\Category;
use App\Domain\Company\Models\Company;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Receiving\Actions\SaveGoodsReceipt;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MultiPtTransferAssetApiTest extends TestCase
{
    use DatabaseTransactions;

    private Company $origin;

    private Company $destination;

    private Category $category;

    private User $operator;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        foreach (['inventory.transfer.view', 'inventory.transfer.create', 'inventory.transfer.send', 'inventory.transfer.receive',
            'assets.view', 'assets.manage', 'distribution.create', 'distribution.send', 'distribution.view',
            'grn.create', 'grn.confirm', 'grn.view', 'items.manage', 'companies.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->origin = Company::query()->create(['name' => 'PT Pengujian Multi GA']);
        $this->destination = Company::query()->create(['name' => 'PT Pengujian Multi K35']);
        $this->category = Category::query()->create(['code' => 'MULTI-PT', 'name' => 'Pengujian Multi PT', 'is_active' => true]);
        $this->operator = $this->actor(['inventory.transfer.view', 'inventory.transfer.create', 'inventory.transfer.send', 'inventory.transfer.receive', 'assets.view', 'assets.manage']);
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actor(array $permissions): User
    {
        $role = Role::findOrCreate('multi_pt_test_role_'.(++$this->serial), 'web');
        $role->syncPermissions($permissions);
        $actor = User::factory()->create(['is_active' => true])->assignRole($role);
        Sanctum::actingAs($actor, ['*']);

        return $actor;
    }

    private function item(bool $asset = false): Item
    {
        return Item::query()->create([
            'category_id' => $this->category->id, 'sku' => 'MULTI-'.Str::upper(Str::random(8)),
            'name' => $asset ? 'Mouse Aset' : 'Kertas Habis Pakai', 'unit' => 'pcs',
            'item_type' => $asset ? 'office_asset' : 'consumable', 'minimum_stock' => '1.000', 'is_active' => true,
        ]);
    }

    private function stock(Item $item, int $quantity, ?Company $company = null): void
    {
        $company ??= $this->origin;
        DB::transaction(function () use ($item, $quantity, $company): void {
            app(InventoryLedger::class)->change($item, (int) $company->id, $quantity, (int) $this->operator->id,
                'adjustment', ['operation_key' => 'test:'.Str::uuid()], 'Saldo awal pengujian multi PT');
            if ($item->item_type === 'office_asset') {
                app(AssetWorkflow::class)->registerUnits($item, (int) $company->id, intdiv($quantity, 1000), (int) $this->operator->id,
                    ['source_type' => 'test_stock', 'source_id' => (int) $item->id * 100000 + (int) $company->id]);
            }
        });
    }

    private function balance(Item $item, Company $company): int
    {
        return app(InventoryLedger::class)->balance($item, (int) $company->id);
    }

    private function payload(Item $item, string $quantity = '3.000', array $assetIds = []): array
    {
        return ['from_company_id' => $this->origin->id, 'to_company_id' => $this->destination->id,
            'transfer_date' => '2026-11-26', 'notes' => 'Pemindahan stok untuk pengujian',
            'items' => [['item_id' => $item->id, 'quantity' => $quantity, 'asset_ids' => $assetIds]]];
    }

    private function draft(array $payload): int
    {
        return (int) $this->postJson('/api/v1/inventory-transfers', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    private function transferAction(int $id, string $action): TestResponse
    {
        return $this->postJson('/api/v1/inventory-transfers/'.$id.'/'.$action);
    }

    private function assets(Item $item): array
    {
        return OfficeAsset::query()->where('item_id', $item->id)->orderBy('id')->pluck('id')->all();
    }

    private function assetAction(int $id, string $action, array $data = [], ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/assets/'.$id.'/'.$action, $data + ['operation_key' => $key ?? (string) Str::uuid()]);
    }

    private function admin(array $permissions): User
    {
        $actor = $this->actor($permissions);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function sentPo(Item $item, string $quantity): PurchaseOrder
    {
        $supplier = Supplier::query()->firstOrCreate(['code' => 'MULTI-PT-SUP'], [
            'name' => 'Supplier Multi PT', 'normalized_name' => 'supplier multi pt', 'is_active' => true,
        ]);
        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-MULTI-'.Str::ulid(), 'company_id' => $this->origin->id,
            'company_snapshot' => ['id' => $this->origin->id, 'name' => $this->origin->name],
            'supplier_id' => $supplier->id, 'supplier_name_snapshot' => $supplier->name, 'supplier_code_snapshot' => $supplier->code,
            'created_by' => $this->operator->id, 'order_date' => '2026-11-26', 'status' => 'sent', 'sent_at' => now(),
            'requested_for_employee_name_snapshot' => 'Budi', 'requested_for_department_name_snapshot' => 'General Affairs',
        ]);
        $po->items()->create([
            'item_id' => $item->id, 'item_sku_snapshot' => $item->sku, 'item_name_snapshot' => $item->name,
            'unit_snapshot' => $item->unit, 'quantity' => $quantity, 'unit_price' => '10000.00', 'line_subtotal' => '20000.00',
        ]);
        Order::query()->create(['purchase_order_id' => $po->id, 'status' => 'pending']);

        return $po;
    }

    public function test_classification_preserves_active_fractional_orders_and_receipts(): void
    {
        $item = $this->item(false);
        $this->stock($item, 1000);
        $po = $this->sentPo($item, '1.500');
        $this->admin(['items.manage', 'items.view']);
        $this->patchJson('/api/v1/items/'.$item->id, ['item_type' => 'office_asset'])
            ->assertUnprocessable()->assertJsonValidationErrors('item_type');
        $po->items()->update(['quantity' => '2.000']);
        app(SaveGoodsReceipt::class)->create([
            'purchase_order_id' => $po->id, 'company_id' => $this->origin->id, 'received_date' => '2026-11-26',
            'items' => [['purchase_order_item_id' => $po->items()->firstOrFail()->id, 'qty_received' => '1.500', 'qty_damaged' => '0.000']],
        ], (int) $this->operator->id);
        $this->patchJson('/api/v1/items/'.$item->id, ['item_type' => 'office_asset'])
            ->assertUnprocessable()->assertJsonValidationErrors('item_type');
        $this->assertSame('consumable', $item->fresh()->item_type);
        $this->assertSame([], $this->assets($item));
        $this->assertSame(1000, $this->balance($item, $this->origin));
    }

    public function test_confirmed_asset_grn_defaults_to_issuer_pt_and_accepts_explicit_destination_without_duplicate_units(): void
    {
        $item = $this->item(true);
        $this->actor(['grn.create', 'grn.confirm', 'grn.view']);
        $first = $this->sentPo($item, '2.000');
        $payload = ['purchase_order_id' => $first->id, 'received_date' => '2026-11-26', 'items' => [[
            'purchase_order_item_id' => $first->items()->sole()->id, 'qty_received' => '2.000', 'qty_damaged' => '0.000',
        ]]];
        $id = $this->postJson('/api/v1/goods-receipts', $payload)->assertCreated()
            ->assertJsonPath('data.company_id', $this->origin->id)->json('data.id');
        $this->assertSame([], $this->assets($item));
        $this->assertSame(0, $this->balance($item, $this->origin));
        $this->postJson('/api/v1/goods-receipts/'.$id.'/confirm')->assertOk();
        $this->postJson('/api/v1/goods-receipts/'.$id.'/confirm')->assertOk();
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $this->assertSame(2, OfficeAsset::query()->where('item_id', $item->id)->where('company_id', $this->origin->id)->count());

        $second = $this->sentPo($item, '2.000');
        $payload = ['purchase_order_id' => $second->id, 'company_id' => $this->destination->id, 'received_date' => '2026-11-26', 'items' => [[
            'purchase_order_item_id' => $second->items()->sole()->id, 'qty_received' => '2.000', 'qty_damaged' => '1.000',
        ]]];
        $other = $this->postJson('/api/v1/goods-receipts', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/goods-receipts/'.$other.'/confirm')->assertOk()->assertJsonPath('data.status', 'discrepancy');
        $this->postJson('/api/v1/goods-receipts/'.$other.'/confirm')->assertOk();
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $this->assertSame(1000, $this->balance($item, $this->destination));
        $this->assertSame(3, OfficeAsset::query()->where('item_id', $item->id)->count());
        $this->assertSame(2, DB::table('stock_movements')->where('item_id', $item->id)->where('movement_type', 'grn_in')->count());
    }

    public function test_distribution_assigns_selected_asset_and_return_restores_pt_available_balance_once(): void
    {
        $item = $this->item(true);
        $this->stock($item, 3000);
        $this->actor(['distribution.create', 'distribution.send', 'distribution.view', 'assets.manage']);
        $asset = $this->assets($item)[0];
        $id = $this->postJson('/api/v1/distributions', [
            'company_id' => $this->origin->id, 'recipient_name' => 'Budi Tanpa Akun', 'purpose' => 'Pemakaian mouse kantor',
            'items' => [['item_id' => $item->id, 'quantity' => '1.000', 'asset_ids' => [$asset]]],
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/distributions/'.$id.'/send')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/distributions/'.$id.'/send')->assertOk();
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $this->assertDatabaseHas('office_assets', ['id' => $asset, 'company_id' => $this->origin->id, 'status' => 'in_use', 'holder_name' => 'Budi Tanpa Akun']);
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $item->id)->where('movement_type', 'distribution_out')->count());
        $this->assetAction($asset, 'return', ['condition' => 'good', 'reason' => 'Sudah selesai dipakai'])->assertOk();
        $this->assertSame(3000, $this->balance($item, $this->origin));
        $this->assertDatabaseHas('office_assets', ['id' => $asset, 'status' => 'available', 'holder_name' => null]);
    }

    public function test_reclassification_registers_each_pt_units_without_duplicate_codes_or_balance_changes(): void
    {
        $item = $this->item();
        $this->stock($item, 2000);
        $this->stock($item, 3000, $this->destination);
        $this->admin(['items.manage']);
        $this->patchJson('/api/v1/items/'.$item->id, ['item_type' => 'office_asset'])->assertOk()->assertJsonPath('data.item_type', 'office_asset');
        $this->patchJson('/api/v1/items/'.$item->id, ['item_type' => 'office_asset'])->assertOk();
        $this->assertSame(2, OfficeAsset::query()->where('item_id', $item->id)->where('company_id', $this->origin->id)->count());
        $this->assertSame(3, OfficeAsset::query()->where('item_id', $item->id)->where('company_id', $this->destination->id)->count());
        $this->assertSame(5, OfficeAsset::query()->where('item_id', $item->id)->distinct()->count('asset_code'));
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $this->assertSame(3000, $this->balance($item, $this->destination));
        $this->patchJson('/api/v1/items/'.$item->id, ['item_type' => 'consumable'])->assertUnprocessable();
    }

    public function test_in_transit_consumable_cannot_be_reclassified_until_received(): void
    {
        $item = $this->item();
        $this->stock($item, 3000);
        $id = $this->draft($this->payload($item, '2.000'));
        $this->transferAction($id, 'send')->assertOk();
        $this->admin(['items.manage', 'inventory.transfer.receive']);
        $this->patchJson('/api/v1/items/'.$item->id, ['item_type' => 'office_asset'])->assertUnprocessable();
        $this->assertSame([], $this->assets($item));
        $this->transferAction($id, 'receive')->assertOk();
        $this->assertSame(1000, $this->balance($item, $this->origin));
        $this->assertSame(2000, $this->balance($item, $this->destination));
    }

    public function test_company_cannot_be_deleted_with_stock_in_transit_or_assigned_assets(): void
    {
        $item = $this->item();
        $this->stock($item, 1000);
        $admin = $this->admin(['companies.manage', 'inventory.transfer.create', 'inventory.transfer.send', 'inventory.transfer.receive', 'assets.manage']);
        $this->deleteJson('/api/v1/companies/'.$this->origin->id)->assertStatus(409);
        $transfer = $this->draft($this->payload($item, '1.000'));
        $this->transferAction($transfer, 'send')->assertOk();
        $this->assertSame(0, $this->balance($item, $this->origin));
        $this->deleteJson('/api/v1/companies/'.$this->origin->id)->assertStatus(409);
        $this->deleteJson('/api/v1/companies/'.$this->destination->id)->assertStatus(409);
        $this->transferAction($transfer, 'receive')->assertOk();
        $this->deleteJson('/api/v1/companies/'.$this->origin->id)->assertOk();
        $this->assertNotNull(Company::withTrashed()->findOrFail($this->origin->id)->deleted_at);

        $assetItem = $this->item(true);
        $heldLocation = Company::query()->create(['name' => 'PT Aset Digunakan']);
        $this->stock($assetItem, 1000, $heldLocation);
        $this->assetAction($this->assets($assetItem)[0], 'assign', ['holder_name' => 'Budi'])->assertOk();
        $this->assertSame(0, $this->balance($assetItem, $heldLocation));
        $this->deleteJson('/api/v1/companies/'.$heldLocation->id)->assertStatus(409);

        $empty = Company::query()->create(['name' => 'PT Kosong Dapat Dihapus']);
        $admin->default_company_id = $empty->id;
        $admin->save();
        $this->deleteJson('/api/v1/companies/'.$empty->id)->assertOk();
        $this->assertNull($admin->fresh()->default_company_id);
    }

    public function test_transfer_conserves_stock_in_transit_then_credits_destination_once(): void
    {
        $item = $this->item();
        $this->stock($item, 10000);
        $id = $this->draft($this->payload($item));
        $this->assertSame(10000, $this->balance($item, $this->origin));
        $this->transferAction($id, 'send')->assertOk()->assertJsonPath('data.status', 'sent');
        $this->transferAction($id, 'send')->assertOk();
        $this->assertSame(7000, $this->balance($item, $this->origin));
        $this->assertSame(0, $this->balance($item, $this->destination));
        $inTransit = DB::table('inventory_transfer_items as line')->join('inventory_transfers as transfer', 'transfer.id', '=', 'line.inventory_transfer_id')
            ->where('transfer.status', 'sent')->where('line.item_id', $item->id)->sum('line.quantity');
        $this->assertSame(3, (int) $inTransit);
        $this->transferAction($id, 'receive')->assertOk()->assertJsonPath('data.status', 'received');
        $this->transferAction($id, 'receive')->assertOk();
        $this->transferAction($id, 'send')->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame(7000, $this->balance($item, $this->origin));
        $this->assertSame(3000, $this->balance($item, $this->destination));
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $item->id)->where('movement_type', 'transfer_out')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $item->id)->where('movement_type', 'transfer_in')->count());
        $this->assertSame(['created', 'sent', 'received'], DB::table('inventory_transfer_events')->where('inventory_transfer_id', $id)->orderBy('id')->pluck('action')->all());
    }

    public function test_same_company_and_insufficient_stock_are_rejected_without_mutation(): void
    {
        $item = $this->item();
        $this->stock($item, 2000);
        $payload = $this->payload($item);
        $payload['to_company_id'] = $this->origin->id;
        $this->postJson('/api/v1/inventory-transfers', $payload)->assertUnprocessable()->assertJsonValidationErrors('to_company_id');
        $id = $this->draft($this->payload($item));
        $this->transferAction($id, 'send')->assertStatus(409);
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $this->assertDatabaseHas('inventory_transfers', ['id' => $id, 'status' => 'draft', 'sent_at' => null]);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'transfer_out')->count());
    }

    public function test_later_line_failure_rolls_back_asset_status_and_first_stock_change(): void
    {
        $assetItem = $this->item(true);
        $emptyItem = $this->item();
        $this->stock($assetItem, 3000);
        $ids = $this->assets($assetItem);
        $payload = $this->payload($assetItem, '1.000', [$ids[0]]);
        $payload['items'][] = ['item_id' => $emptyItem->id, 'quantity' => '1.000'];
        $id = $this->draft($payload);
        $this->transferAction($id, 'send')->assertStatus(409);
        $this->assertSame(3000, $this->balance($assetItem, $this->origin));
        $this->assertDatabaseHas('office_assets', ['id' => $ids[0], 'status' => 'available']);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'transfer_out')->count());
        $this->assertSame(0, DB::table('asset_events')->where('action', 'transfer_sent')->count());
    }

    public function test_draft_cancellation_has_no_stock_effect_and_sent_transfer_cannot_be_cancelled(): void
    {
        $item = $this->item();
        $this->stock($item, 5000);
        $cancelled = $this->draft($this->payload($item));
        $this->transferAction($cancelled, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->transferAction($cancelled, 'cancel')->assertOk();
        $this->transferAction($cancelled, 'send')->assertStatus(409);
        $this->assertSame(5000, $this->balance($item, $this->origin));
        $sent = $this->draft($this->payload($item));
        $this->transferAction($sent, 'send')->assertOk();
        $this->transferAction($sent, 'cancel')->assertStatus(409);
    }

    public function test_asset_transfer_preserves_identity_and_prevents_concurrent_drafts_sending_the_same_unit(): void
    {
        $item = $this->item(true);
        $this->stock($item, 3000);
        $ids = $this->assets($item);
        $first = $this->draft($this->payload($item, '1.000', [$ids[0]]));
        $second = $this->draft($this->payload($item, '1.000', [$ids[0]]));
        $this->transferAction($first, 'send')->assertOk();
        $this->transferAction($second, 'send')->assertStatus(409);
        $this->assertDatabaseHas('office_assets', ['id' => $ids[0], 'status' => 'in_transit', 'company_id' => $this->origin->id]);
        $this->transferAction($first, 'receive')->assertOk();
        $this->transferAction($first, 'receive')->assertOk();
        $this->assertDatabaseHas('office_assets', ['id' => $ids[0], 'status' => 'available', 'company_id' => $this->destination->id]);
        $this->assertSame(3, OfficeAsset::query()->where('item_id', $item->id)->count());
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $this->assertSame(1000, $this->balance($item, $this->destination));
    }

    public function test_asset_assign_reassign_return_and_request_replay_preserve_controlled_units(): void
    {
        $item = $this->item(true);
        $this->stock($item, 3000);
        $id = $this->assets($item)[0];
        $key = (string) Str::uuid();
        $this->assetAction($id, 'assign', ['holder_name' => 'Budi'], $key)->assertOk()->assertJsonPath('data.status', 'in_use');
        $this->assetAction($id, 'assign', ['holder_name' => 'Budi'], $key)->assertOk();
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $this->assetAction($id, 'reassign', ['holder_name' => 'Siti'])->assertOk()->assertJsonPath('data.holder_name', 'Siti');
        $this->assertSame(2000, $this->balance($item, $this->origin));
        $returnKey = (string) Str::uuid();
        $this->assetAction($id, 'return', ['condition' => 'good'], $returnKey)->assertOk()->assertJsonPath('data.status', 'available')->assertJsonPath('data.holder_name', null);
        $this->assetAction($id, 'return', ['condition' => 'good'], $returnKey)->assertOk();
        $this->assertSame(3000, $this->balance($item, $this->origin));
        $this->assertSame(3, OfficeAsset::query()->where('item_id', $item->id)->count());
        $this->assertSame(1, DB::table('asset_events')->where('office_asset_id', $id)->where('action', 'assign')->count());
        $this->assetAction($id, 'assign', ['holder_name' => 'Orang lain'], $key)->assertStatus(409);
    }

    public function test_damaged_return_remains_unavailable_until_inspection_repairs_it(): void
    {
        $item = $this->item(true);
        $this->stock($item, 1000);
        $id = $this->assets($item)[0];
        $this->assetAction($id, 'assign', ['holder_name' => 'Budi'])->assertOk();
        $this->assetAction($id, 'return', ['condition' => 'damaged'])->assertOk()->assertJsonPath('data.status', 'damaged');
        $this->assertSame(0, $this->balance($item, $this->origin));
        $this->assetAction($id, 'assign', ['holder_name' => 'Siti'])->assertStatus(409);
        $this->assetAction($id, 'condition', ['condition' => 'fair', 'serial_number' => 'SERIAL-OPSIONAL'])->assertOk()
            ->assertJsonPath('data.status', 'available')->assertJsonPath('data.serial_number', 'SERIAL-OPSIONAL')
            ->assertJsonPath('data.events.3.reference.from_condition', 'damaged')
            ->assertJsonPath('data.events.3.reference.to_condition', 'fair')
            ->assertJsonPath('data.events.3.reference.from_serial_number', null)
            ->assertJsonPath('data.events.3.reference.to_serial_number', 'SERIAL-OPSIONAL');
        $this->assertSame(1000, $this->balance($item, $this->origin));
    }

    public function test_consumables_do_not_become_assets_and_fractional_asset_transfer_is_rejected(): void
    {
        $consumable = $this->item();
        $this->stock($consumable, 1250);
        $this->assertSame([], $this->assets($consumable));
        $this->getJson('/api/v1/assets?item_id='.$consumable->id)->assertOk()->assertJsonCount(0, 'data');
        $asset = $this->item(true);
        $this->stock($asset, 2000);
        $this->postJson('/api/v1/inventory-transfers', $this->payload($asset, '1.500', [$this->assets($asset)[0]]))->assertStatus(409);
        $id = $this->draft($this->payload($consumable, '1.250'));
        $this->transferAction($id, 'send')->assertOk();
        $this->transferAction($id, 'receive')->assertOk();
        $this->assertSame(1250, $this->balance($consumable, $this->destination));
    }

    public function test_missing_permissions_return_403_and_lookup_does_not_expose_holder_history(): void
    {
        $item = $this->item(true);
        $this->stock($item, 2000);
        $ids = $this->assets($item);
        $transfer = $this->draft($this->payload($item, '1.000', [$ids[0]]));
        $this->assetAction($ids[1], 'assign', ['holder_name' => 'Pemegang Rahasia'])->assertOk();
        $this->actor([]);
        $this->getJson('/api/v1/inventory-transfers')->assertForbidden();
        $this->postJson('/api/v1/inventory-transfers', $this->payload($item))->assertForbidden();
        $this->transferAction($transfer, 'send')->assertForbidden();
        $this->transferAction($transfer, 'receive')->assertForbidden();
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->assetAction($ids[0], 'assign', ['holder_name' => 'Budi'])->assertForbidden();
        $this->actor(['distribution.create']);
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/inventory/asset-options?company_id='.$this->origin->id.'&item_id='.$item->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[0])
            ->assertJsonMissingPath('data.0.holder_name')->assertJsonMissingPath('data.0.events');
    }
}
