<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StockAdjustmentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        Permission::findOrCreate('inventory.view', 'web');
        Permission::findOrCreate('inventory.adjust', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actingWith(array $permissions): User
    {
        $role = Role::findOrCreate('inventory_test_role', 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function item(): Item
    {
        $category = Category::query()->create([
            'code' => 'STK', 'name' => 'Stok Testing', 'is_active' => true,
        ]);

        return Item::query()->create([
            'category_id' => $category->id,
            'sku' => 'STK-001',
            'name' => 'Barang Stok',
            'unit' => 'pcs',
            'minimum_stock' => '3.000',
            'is_active' => true,
        ]);
    }

    private function payload(string $expected = '0.000', string $target = '8.250'): array
    {
        return [
            'expected_quantity' => $expected,
            'target_quantity' => $target,
            'reason' => 'Hasil opname fisik gudang',
        ];
    }

    public function test_guest_is_unauthorized(): void
    {
        $item = $this->item();
        $this->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload())
            ->assertUnauthorized();
        $this->getJson('/api/v1/stock-movements')->assertUnauthorized();
    }

    public function test_role_without_permission_is_forbidden(): void
    {
        $this->actingWith([]);
        $item = $this->item();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload())
            ->assertForbidden();
        $this->getJson('/api/v1/stock-movements')->assertForbidden();
    }

    public function test_viewer_can_read_audit_but_cannot_adjust(): void
    {
        $this->actingWith(['inventory.view']);
        $item = $this->item();
        $this->getJson('/api/v1/stock-movements')->assertOk();
        $this->getJson('/api/v1/stock-adjustments')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload())
            ->assertForbidden();
    }

    public function test_adjustment_creates_one_audited_movement(): void
    {
        $this->actingWith(['inventory.view', 'inventory.adjust']);
        $item = $this->item();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.stock_before', '0.000')
            ->assertJsonPath('data.stock_after', '8.250')
            ->assertJsonPath('data.quantity_difference', '8.250');

        $this->assertSame('8.250', (string) $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'adjustment',
            'quantity_change' => '8.250',
            'balance_before' => '0.000',
            'balance_after' => '8.250',
        ]);
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $item->id)->count());
    }

    public function test_same_idempotency_key_and_payload_is_replayed_without_new_mutation(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $key = (string) Str::uuid();
        $endpoint = '/api/v1/items/'.$item->id.'/stock-adjustments';

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson($endpoint, $this->payload())->assertCreated();
        $this->withHeader('Idempotency-Key', $key)
            ->postJson($endpoint, $this->payload())->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, DB::table('stock_adjustments')->where('item_id', $item->id)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $item->id)->count());
        $this->assertSame('8.250', (string) $item->fresh()->stock_quantity);
    }

    public function test_reusing_key_for_different_payload_returns_409(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $key = (string) Str::uuid();
        $endpoint = '/api/v1/items/'.$item->id.'/stock-adjustments';

        $this->withHeader('Idempotency-Key', $key)
            ->postJson($endpoint, $this->payload())->assertCreated();
        $this->withHeader('Idempotency-Key', $key)
            ->postJson($endpoint, $this->payload('0.000', '9.000'))
            ->assertStatus(409);

        $this->assertSame('8.250', (string) $item->fresh()->stock_quantity);
    }

    public function test_stale_expected_balance_returns_409_and_rolls_back(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload('1.000', '5.000'))
            ->assertStatus(409);

        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('stock_movements')->where('item_id', $item->id)->count());
        $this->assertSame(0, DB::table('stock_adjustments')->where('item_id', $item->id)->count());
    }

    public function test_no_change_is_rejected(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload('0.000', '0.000'))
            ->assertStatus(409);
    }

    public function test_missing_idempotency_key_is_validation_error(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $this->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_negative_or_excess_decimal_target_is_rejected(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $endpoint = '/api/v1/items/'.$item->id.'/stock-adjustments';

        foreach (['-1.000', '1.0009', '1000000000000000.000'] as $invalid) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson($endpoint, $this->payload('0.000', $invalid))
                ->assertUnprocessable()->assertJsonValidationErrors('target_quantity');
        }
    }

    public function test_client_cannot_override_audit_fields(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items/'.$item->id.'/stock-adjustments',
                $this->payload() + ['adjusted_by' => 999, 'stock_before' => '100.000'])
            ->assertUnprocessable();
        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
    }

    public function test_negative_adjustment_can_reduce_to_zero(): void
    {
        $this->actingWith(['inventory.adjust']);
        $item = $this->item();
        $endpoint = '/api/v1/items/'.$item->id.'/stock-adjustments';
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($endpoint, $this->payload('0.000', '5.000'))->assertCreated();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($endpoint, $this->payload('5.000', '0.000'))
            ->assertCreated()->assertJsonPath('data.quantity_difference', '-5.000');
        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
    }

    public function test_movements_can_be_filtered_and_paginated(): void
    {
        $this->actingWith(['inventory.view', 'inventory.adjust']);
        $item = $this->item();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/items/'.$item->id.'/stock-adjustments', $this->payload())
            ->assertCreated();

        $this->getJson('/api/v1/stock-movements?item_id='.$item->id.'&movement_type=adjustment&per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.balance_after', '8.250')
            ->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v1/stock-adjustments?item_id='.$item->id)
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
