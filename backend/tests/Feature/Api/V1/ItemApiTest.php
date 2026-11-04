<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ItemApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name,
            'Test hanya boleh berjalan pada purchasing_test.',
        );

        Permission::findOrCreate('items.view', 'web');
        Permission::findOrCreate('items.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actingWith(array $permissions): User
    {
        $role = Role::findOrCreate('item_test_role', 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function category(bool $active = true, string $code = 'ATK'): Category
    {
        return Category::query()->create([
            'code' => $code,
            'name' => 'Kategori '.$code,
            'is_active' => $active,
        ]);
    }

    private function item(Category $category, string $sku = 'BRG-001', array $data = []): Item
    {
        return Item::query()->create(array_merge([
            'category_id' => $category->id,
            'sku' => $sku,
            'name' => 'Pulpen',
            'unit' => 'pcs',
            'minimum_stock' => '5.000',
            'is_active' => true,
        ], $data));
    }

    public function test_guest_cannot_list_items(): void
    {
        $this->getJson('/api/v1/items')->assertUnauthorized();
    }

    public function test_role_without_item_permission_is_forbidden(): void
    {
        $this->actingWith([]);

        $this->getJson('/api/v1/items')->assertForbidden();
        $this->postJson('/api/v1/items', [])->assertForbidden();
    }

    public function test_viewer_can_read_but_cannot_write(): void
    {
        $this->actingWith(['items.view']);
        $item = $this->item($this->category());

        $this->getJson('/api/v1/items/'.$item->id)
            ->assertOk()->assertJsonPath('data.sku', 'BRG-001');

        $this->postJson('/api/v1/items', [
            'category_id' => $item->category_id,
            'sku' => 'BRG-002',
            'name' => 'Spidol',
            'unit' => 'pcs',
        ])->assertForbidden();

        $this->deleteJson('/api/v1/items/'.$item->id)
            ->assertForbidden();
    }

    public function test_manager_can_create_normalized_item_with_safe_defaults(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category();

        $this->postJson('/api/v1/items', [
            'category_id' => $category->id,
            'sku' => '  brg-atk_01 ',
            'name' => '  Pulpen    Hitam ',
            'unit' => ' PCS ',
            'minimum_stock' => '10.500',
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'BRG-ATK_01')
            ->assertJsonPath('data.name', 'Pulpen Hitam')
            ->assertJsonPath('data.unit', 'pcs')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.minimum_stock', '10.500')
            ->assertJsonPath('data.stock_quantity', '0.000')
            ->assertJsonPath('data.last_purchase_price', '0.00')
            ->assertJsonPath('data.is_low_stock', true)
            ->assertJsonPath('data.category.code', 'ATK');

        $this->assertDatabaseHas('items', [
            'sku' => 'BRG-ATK_01',
            'name' => 'Pulpen Hitam',
        ]);
    }

    public function test_manager_can_create_inactive_item(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category();

        $this->postJson('/api/v1/items', [
            'category_id' => $category->id,
            'sku' => 'NONAKTIF-01',
            'name' => 'Barang Arsip',
            'unit' => 'box',
            'is_active' => false,
        ])->assertCreated()->assertJsonPath('data.is_active', false);
    }

    public function test_duplicate_sku_after_normalization_returns_422(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category();
        $this->item($category, 'BRG-001');

        $this->postJson('/api/v1/items', [
            'category_id' => $category->id,
            'sku' => 'brg-001',
            'name' => 'Duplikat',
            'unit' => 'pcs',
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_inactive_category_cannot_be_used_for_new_item(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category(false);

        $this->postJson('/api/v1/items', [
            'category_id' => $category->id,
            'sku' => 'ITEM-BLOCK',
            'name' => 'Ditolak',
            'unit' => 'pcs',
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_create_does_not_allow_stock_or_price_from_client(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category();
        $payload = [
            'category_id' => $category->id,
            'sku' => 'NO-STOCK',
            'name' => 'Barang',
            'unit' => 'pcs',
        ];

        $this->postJson('/api/v1/items', $payload + ['stock_quantity' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');

        $this->postJson('/api/v1/items', $payload + ['last_purchase_price' => 99000])
            ->assertUnprocessable()->assertJsonValidationErrors('last_purchase_price');

        $this->assertDatabaseMissing('items', ['sku' => 'NO-STOCK']);
    }

    public function test_update_cannot_change_stock_or_price(): void
    {
        $this->actingWith(['items.manage']);
        $item = $this->item($this->category());

        $this->patchJson('/api/v1/items/'.$item->id, [
            'stock_quantity' => '999.000',
            'last_purchase_price' => '5000.00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_quantity', 'last_purchase_price']);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'stock_quantity' => '0.000',
            'last_purchase_price' => '0.00',
        ]);
    }

    public function test_manager_can_edit_master_fields_and_deactivate(): void
    {
        $this->actingWith(['items.manage']);
        $item = $this->item($this->category());

        $this->patchJson('/api/v1/items/'.$item->id, [
            'name' => '  Pulpen    Biru ',
            'minimum_stock' => '12.250',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Pulpen Biru')
            ->assertJsonPath('data.minimum_stock', '12.250')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.stock_quantity', '0.000');
    }

    public function test_update_rejects_duplicate_sku(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category();
        $this->item($category, 'SKU-001');
        $item = $this->item($category, 'SKU-002');

        $this->patchJson('/api/v1/items/'.$item->id, [
            'sku' => 'sku-001',
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_update_rejects_moving_to_inactive_category(): void
    {
        $this->actingWith(['items.manage']);
        $item = $this->item($this->category());
        $inactive = $this->category(false, 'OLD');

        $this->patchJson('/api/v1/items/'.$item->id, [
            'category_id' => $inactive->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_low_stock_filter_uses_balance_lte_minimum(): void
    {
        $this->actingWith(['items.view']);
        $category = $this->category();
        $low = $this->item($category, 'LOW', [
            'name' => 'Barang Minimum', 'minimum_stock' => '10.000',
        ]);
        $high = $this->item($category, 'HIGH', [
            'name' => 'Barang Cukup', 'minimum_stock' => '10.000',
        ]);

        // Fixture: set saldo langsung hanya pada test, bukan endpoint produksi.
        DB::table('items')->where('id', $low->id)
            ->update(['stock_quantity' => '10.000']);
        DB::table('items')->where('id', $high->id)
            ->update(['stock_quantity' => '10.001']);

        $this->getJson('/api/v1/items?low_stock=1&is_active=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'LOW')
            ->assertJsonPath('data.0.is_low_stock', true);

        $this->getJson('/api/v1/items?low_stock=0&is_active=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'HIGH')
            ->assertJsonPath('data.0.is_low_stock', false);
    }

    public function test_list_supports_search_category_and_pagination(): void
    {
        $this->actingWith(['items.view']);
        $category = $this->category();
        $this->item($category, 'PEN-001', ['name' => 'Pulpen Biru']);
        $this->item($category, 'BOOK-01', ['name' => 'Buku Tulis']);

        $this->getJson(
            '/api/v1/items?search=Pulpen&category_id='.$category->id.'&per_page=5'
        )->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'PEN-001')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_unused_zero_stock_item_can_be_deleted(): void
    {
        $this->actingWith(['items.manage']);
        $item = $this->item($this->category());

        $this->deleteJson('/api/v1/items/'.$item->id)->assertOk();
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_item_with_stock_cannot_be_deleted(): void
    {
        $this->actingWith(['items.manage']);
        $item = $this->item($this->category());
        DB::table('items')->where('id', $item->id)
            ->update(['stock_quantity' => '2.000']);

        $this->deleteJson('/api/v1/items/'.$item->id)->assertStatus(409);
        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_item_with_adjustment_history_cannot_be_deleted(): void
    {
        $actor = $this->actingWith(['items.manage']);
        $item = $this->item($this->category());

        DB::table('stock_adjustments')->insert([
            'item_id' => $item->id,
            'adjusted_by' => $actor->id,
            'stock_before' => '0.000',
            'stock_after' => '0.000',
            'quantity_difference' => '0.000',
            'reason' => 'Audit stok awal untuk test',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson('/api/v1/items/'.$item->id)->assertStatus(409);
        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_minimum_stock_rejects_negative_and_oversized_values(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category();
        $payload = [
            'category_id' => $category->id,
            'sku' => 'MIN-STOCK-01',
            'name' => 'Barang Validasi',
            'unit' => 'pcs',
        ];

        foreach (['-1', '1.2345', '1234567890123456.000'] as $invalid) {
            $this->postJson('/api/v1/items', $payload + [
                'minimum_stock' => $invalid,
            ])->assertUnprocessable()->assertJsonValidationErrors('minimum_stock');
        }
    }

    public function test_reactivation_fails_if_current_category_was_deactivated(): void
    {
        $this->actingWith(['items.manage']);
        $category = $this->category();
        $item = $this->item($category, 'ARCHIVE-01', ['is_active' => false]);

        $category->update(['is_active' => false]);

        $this->patchJson('/api/v1/items/'.$item->id, [
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_unknown_item_returns_404(): void
    {
        $this->actingWith(['items.view']);
        $this->getJson('/api/v1/items/999999999')->assertNotFound();
    }
}
