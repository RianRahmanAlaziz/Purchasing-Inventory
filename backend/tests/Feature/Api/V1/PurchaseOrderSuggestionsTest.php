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

final class PurchaseOrderSuggestionsTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    private Supplier $supplier;

    private Item $item;

    private Category $category;

    private Employee $employee;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->signIn(['po.view', 'po.create', 'po.update', 'suppliers.view', 'suppliers.create', 'items.view', 'items.manage']);
        $this->supplier = Supplier::query()->create(['code' => 'SUGGEST', 'name' => 'PT Supplier Baru',
            'normalized_name' => 'pt supplier baru', 'address' => 'Alamat master', 'is_active' => true]);
        $this->category = Category::query()->create(['code' => 'SUGGEST', 'name' => 'Kategori Sugesti', 'is_active' => true]);
        $this->item = Item::query()->create(['category_id' => $this->category->id, 'sku' => 'STOK-101', 'name' => 'Barang Stok',
            'unit' => 'pcs', 'minimum_stock' => '0', 'is_active' => true]);
        DB::table('items')->where('id', $this->item->id)->update(['stock_quantity' => '5.000', 'last_purchase_price' => '5000.00']);
        $this->department = Department::query()->create(['code' => 'SUGGEST', 'name' => 'Operasional', 'is_active' => true]);
        $this->employee = Employee::query()->create(['employee_number' => 'SUGGEST', 'name' => 'Pemohon',
            'department_id' => $this->department->id, 'is_active' => true]);
    }

    private function signIn(array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $role = Role::findOrCreate('suggestion-test-'.count($names), 'web');
        $role->syncPermissions($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor = User::factory()->create(['is_active' => true])->assignRole($role);
        Sanctum::actingAs($this->actor, ['*']);
    }

    private function payload(): array
    {
        return ['supplier_id' => $this->supplier->id, 'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id, 'order_date' => '2026-11-05',
            'items' => [['item_id' => $this->item->id, 'quantity' => '1.000', 'unit_price' => '7500.00']]];
    }

    private function freePayload(): array
    {
        $payload = $this->payload();
        unset($payload['supplier_id']);
        $payload['supplier_name'] = 'CV Supplier Bebas';
        $payload['supplier_address'] = "Jalan Supplier Baru\nBandung";
        $payload['items'] = [['name' => 'Barang Baru', 'unit' => 'pcs', 'category_id' => $this->category->id,
            'quantity' => '2.000', 'unit_price' => '12500.00']];

        return $payload;
    }

    public function test_suggestions_include_po_snapshots_and_stock_with_the_correct_identity(): void
    {
        $payload = $this->payload();
        $payload['supplier_name'] = 'PT Supplier Lama';
        $payload['supplier_address'] = 'Alamat PO lama';
        $payload['items'][0]['description'] = 'Nama Barang Lama';
        app(SaveDraftPurchaseOrder::class)->create($payload, (int) $this->actor->id);
        $this->getJson('/api/v1/purchase-orders/suggestions/suppliers?q=Lama')->assertOk()
            ->assertJsonPath('data.0.id', $this->supplier->id)->assertJsonPath('data.0.name', 'PT Supplier Lama')
            ->assertJsonPath('data.0.address', 'Alamat PO lama')->assertJsonPath('data.0.source', 'history');
        $this->getJson('/api/v1/purchase-orders/suggestions/items?q=Lama')->assertOk()
            ->assertJsonPath('data.0.id', $this->item->id)->assertJsonPath('data.0.name', 'Nama Barang Lama')
            ->assertJsonPath('data.0.stock_name', 'Barang Stok')->assertJsonPath('data.0.source', 'history')
            ->assertJsonPath('data.0.unit_price', '7500.00')->assertJsonPath('data.0.stock_quantity', '5.000');
        $this->getJson('/api/v1/purchase-orders/suggestions/items?q=STOK-101')->assertOk()
            ->assertJsonPath('data.0.id', $this->item->id)->assertJsonPath('data.0.source', 'stock');
        $this->getJson('/api/v1/purchase-orders/suggestions/categories')->assertOk()->assertJsonPath('data.0.id', $this->category->id);
    }

    public function test_free_names_create_linked_masters_without_touching_stock(): void
    {
        $response = $this->postJson('/api/v1/purchase-orders', $this->freePayload())->assertCreated()
            ->assertJsonPath('data.supplier_name_snapshot', 'CV Supplier Bebas')->assertJsonPath('data.items.0.name', 'Barang Baru')
            ->assertJsonPath('data.total_amount', '25000.00');
        $newItem = Item::query()->findOrFail($response->json('data.items.0.item_id'));
        $this->assertSame('0.000', $newItem->stock_quantity);
        $this->assertSame($this->category->id, $newItem->category_id);
        $this->assertStringStartsWith('ITEM-', $newItem->sku);
        $this->assertSame('5.000', $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('suppliers', 2);
        $this->assertDatabaseCount('items', 2);
    }

    public function test_typed_existing_names_reuse_masters_without_create_permissions(): void
    {
        $this->signIn(['po.view', 'po.create']);
        $payload = $this->payload();
        unset($payload['supplier_id'], $payload['items'][0]['item_id']);
        $payload['supplier_name'] = '  pt supplier baru  ';
        $payload['items'][0] += ['name' => 'barang stok', 'unit' => 'pcs'];
        $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()
            ->assertJsonPath('data.supplier_id', $this->supplier->id)->assertJsonPath('data.items.0.item_id', $this->item->id);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('items', 1);
    }

    public function test_repeating_new_names_reuses_the_same_masters(): void
    {
        $payload = $this->freePayload();
        $first = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()->json('data');
        $payload['supplier_name'] = 'cv supplier bebas';
        $payload['items'][0]['name'] = 'barang baru';
        $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()
            ->assertJsonPath('data.supplier_id', $first['supplier_id'])
            ->assertJsonPath('data.items.0.item_id', $first['items'][0]['item_id']);
        $this->assertDatabaseCount('suppliers', 2);
        $this->assertDatabaseCount('items', 2);
    }

    public function test_free_name_edit_changes_po_without_renaming_old_master_records(): void
    {
        $po = $this->postJson('/api/v1/purchase-orders', $this->payload())->assertCreated()->json('data.id');
        $data = $this->freePayload();
        unset($data['requested_for_employee_id'], $data['requested_for_department_id'], $data['order_date']);
        $this->patchJson("/api/v1/purchase-orders/$po", $data)->assertOk()
            ->assertJsonPath('data.supplier_name_snapshot', 'CV Supplier Bebas')->assertJsonPath('data.items.0.name', 'Barang Baru');
        $this->assertSame('PT Supplier Baru', $this->supplier->fresh()->name);
        $this->assertSame('Barang Stok', $this->item->fresh()->name);
    }

    public function test_ambiguous_names_require_a_suggestion_and_new_item_failures_roll_back_everything(): void
    {
        $other = $this->item->replicate();
        $other->sku = 'STOK-102';
        $other->save();
        $data = $this->payload();
        $data['items'] = [['name' => 'Barang Stok', 'unit' => 'pcs', 'quantity' => '1', 'unit_price' => '100']];
        $this->postJson('/api/v1/purchase-orders', $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.name');
        $data['items'][0]['item_id'] = $other->id;
        $this->postJson('/api/v1/purchase-orders', $data)->assertCreated()->assertJsonPath('data.items.0.item_id', $other->id);
        $new = $this->freePayload();
        unset($new['items'][0]['category_id']);
        $this->postJson('/api/v1/purchase-orders', $new)->assertUnprocessable()->assertJsonValidationErrors('items.0.category_id');
        $this->assertDatabaseMissing('suppliers', ['normalized_name' => 'cv supplier bebas']);
        $new['items'][0]['category_id'] = $this->category->id;
        $new['items'][] = $new['items'][0];
        $this->postJson('/api/v1/purchase-orders', $new)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseMissing('items', ['name' => 'Barang Baru']);
    }

    public function test_new_master_permissions_are_enforced_and_failed_creation_leaves_no_orphans(): void
    {
        $this->signIn(['po.view', 'po.create']);
        $this->postJson('/api/v1/purchase-orders', $this->freePayload())->assertForbidden();
        $this->signIn(['po.view', 'po.create', 'suppliers.create']);
        $this->postJson('/api/v1/purchase-orders', $this->freePayload())->assertForbidden();
        $this->assertDatabaseMissing('suppliers', ['normalized_name' => 'cv supplier bebas']);
        $this->assertDatabaseMissing('items', ['name' => 'Barang Baru']);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_suggestions_escape_wildcards_and_hide_inactive_or_deleted_history(): void
    {
        $po = app(SaveDraftPurchaseOrder::class)->create($this->payload(), (int) $this->actor->id);
        $this->getJson('/api/v1/purchase-orders/suggestions/suppliers?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/purchase-orders/suggestions/items?q=_')->assertOk()->assertJsonCount(0, 'data');
        $po->delete();
        $this->supplier->update(['is_active' => false]);
        $this->item->update(['is_active' => false]);
        $this->getJson('/api/v1/purchase-orders/suggestions/suppliers?q=Supplier')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/purchase-orders/suggestions/items?q=Barang')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn(['po.view']);
        $this->getJson('/api/v1/purchase-orders/suggestions/suppliers?q=Supplier')->assertForbidden();
        $this->getJson('/api/v1/purchase-orders/suggestions/items?q=Barang')->assertForbidden();
        $this->getJson('/api/v1/purchase-orders/suggestions/categories')->assertForbidden();
    }
}
