<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Authorization\Services\ModulePermissionRegistrar;
use App\Domain\Category\Models\Category;
use App\Domain\Company\Models\Company;
use App\Domain\Item\Models\Item;
use App\Domain\Receiving\Actions\ConfirmGoodsReceipt;
use App\Domain\Receiving\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MaintenanceAssetRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private const REGISTER =
    '/api/v1/maintenance/asset-registrations/from-grn';

    private const CANDIDATES =
    '/api/v1/maintenance/asset-candidates';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());

        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name
        );

        app(ModulePermissionRegistrar::class)->register([
            'maintenance.assets.register-from-receipt',
            'maintenance.assets.manage-all-companies',
            'maintenance.assets.candidates.view',
            'maintenance.assets.view-all-companies',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        parent::tearDown();
    }

    private function signIn(
        string $roleName = 'hr',
        array $permissions = [
            'maintenance.assets.register-from-receipt',
            'maintenance.assets.manage-all-companies',
            'maintenance.assets.candidates.view',
            'maintenance.assets.view-all-companies',
        ]
    ): User {
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * Membuat penerimaan melalui ConfirmGoodsReceipt existing.
     *
     * @return array{
     *   company_id:int,
     *   grn_item_id:int,
     *   item_id:int,
     *   order_id:int
     * }
     */
    private function receipt(
        bool $confirmed = true,
        string $received = '6.000',
        string $damaged = '0.000',
        bool $eligible = true,
        bool $withCompany = true,
        bool $withPrefix = true,
    ): array {
        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $company = Company::query()->create([
            'name' => 'PT MGA ' . Str::upper(Str::random(10)),
        ]);

        // Prefix khusus data test, bukan kode PT production.
        if ($withPrefix) {
            DB::table('maintenance_asset_sequences')->insert([
                'company_id' => $company->id,
                'prefix' => 'T' . $company->id,
                'last_sequence' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $categoryKey = Str::upper(Str::random(8));

        $category = Category::query()->create([
            'code' => 'MGA' . $categoryKey,
            'name' => 'Peralatan untuk Uji ' . $categoryKey,
            'is_active' => true,
        ]);

        $item = Item::query()->create([
            'category_id' => $category->id,
            'sku' => 'MGA-' . Str::upper(Str::random(10)),
            'name' => 'Laptop untuk Registrasi',
            'unit' => 'pcs',
            'minimum_stock' => '0.000',
            'is_active' => true,
        ]);

        $item->is_asset_trackable = $eligible;
        $item->save();

        $supplierCode = 'SUP-' . Str::upper(Str::random(8));

        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => $supplierCode,
            'name' => 'Supplier MGA',
            'normalized_name' => strtolower($supplierCode),
            'is_active' => true,
        ]);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-' . Str::upper(Str::random(12)),
            'supplier_id' => $supplierId,
            'created_by' => $actor->id,
            'supplier_name_snapshot' => 'Supplier MGA',
            'supplier_code_snapshot' => $supplierCode,
            'company_id' => $withCompany
                ? $company->id
                : null,
            'order_date' => today()->toDateString(),
            'status' => 'sent',
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'purchase_order_id' => $poId,
            'status' => 'pending',
        ]);

        $poItemId = DB::table(
            'purchase_order_items'
        )->insertGetId([
            'purchase_order_id' => $poId,
            'item_id' => $item->id,
            'item_sku_snapshot' => $item->sku,
            'item_name_snapshot' => $item->name,
            'unit_snapshot' => 'pcs',
            'quantity' => '10.000',
            'unit_price' => '100000.00',
            'line_subtotal' => '1000000.00',
        ]);

        $grnId = DB::table(
            'goods_receipts'
        )->insertGetId([
            'grn_number' => 'GRN-' . Str::upper(Str::random(12)),
            'purchase_order_id' => $poId,
            'created_by' => $actor->id,
            'received_date' => today()->toDateString(),
            'status' => 'pending',
        ]);

        $grnItemId = DB::table(
            'goods_receipt_items'
        )->insertGetId([
            'goods_receipt_id' => $grnId,
            'purchase_order_item_id' => $poItemId,
            'qty_received' => $received,
            'qty_damaged' => $damaged,
        ]);

        if ($confirmed) {
            app(ConfirmGoodsReceipt::class)->execute(
                GoodsReceipt::query()->findOrFail($grnId),
                (int) $actor->id
            );
        }

        return [
            'company_id' => (int) $company->id,
            'grn_item_id' => (int) $grnItemId,
            'item_id' => (int) $item->id,
            'order_id' => (int) $orderId,
        ];
    }

    private function payload(
        int $grnItemId,
        int $count = 2,
        ?string $key = null
    ): array {
        return [
            'goods_receipt_item_id' => $grnItemId,
            'idempotency_key' => $key ?? (string) Str::uuid(),

            'units' => array_map(
                static fn(int $index): array => [
                    'asset_name' => 'Laptop ' . ($index + 1),
                    'asset_type' => 'office_equipment',
                    'serial_number' => 'SN-MGA-' . Str::upper(
                        Str::random(12)
                    ),
                ],
                range(0, $count - 1)
            ),
        ];
    }

    public function test_guest_cannot_register(): void
    {
        $this->postJson(self::REGISTER)
            ->assertUnauthorized();
    }

    public function test_staff_hr_is_forbidden_even_if_permissions_were_given(): void
    {
        $this->signIn('staff_hr');

        $r = $this->receipt();

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'])
        )->assertForbidden();
    }

    public function test_hr_needs_company_scope_permission(): void
    {
        $this->signIn('hr', [
            'maintenance.assets.register-from-receipt',
        ]);

        $r = $this->receipt();

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'])
        )->assertForbidden();
    }

    public function test_pending_grn_is_rejected_and_no_assets_are_created(): void
    {
        $this->signIn();

        $r = $this->receipt(confirmed: false);

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'])
        )->assertStatus(409);

        $this->assertDatabaseCount('maintenance_assets', 0);
        $this->assertDatabaseCount(
            'maintenance_asset_registrations',
            0
        );
    }

    public function test_confirmed_grn_does_not_auto_create_assets(): void
    {
        $this->signIn();

        $r = $this->receipt();

        $this->assertDatabaseCount('maintenance_assets', 0);

        $this->getJson(
            self::CANDIDATES . '?company_id=' . $r['company_id']
        )
            ->assertOk()
            ->assertJsonPath(
                'data.0.goods_receipt_item_id',
                $r['grn_item_id']
            )
            ->assertJsonPath(
                'data.0.remaining_units',
                6
            );

        $this->assertDatabaseCount('maintenance_assets', 0);
    }

    public function test_two_batches_register_six_units_and_exhaust_quota(): void
    {
        $this->signIn();

        $r = $this->receipt();

        $first = $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 2)
        )
            ->assertCreated()
            ->assertJsonPath('data.registered_units', 2)
            ->assertJsonPath('data.remaining_units', 4);

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 4)
        )
            ->assertCreated()
            ->assertJsonPath('data.registered_units', 6)
            ->assertJsonPath('data.remaining_units', 0);

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 1)
        )->assertStatus(409);

        $this->assertDatabaseCount('maintenance_assets', 6);

        $this->assertDatabaseCount(
            'maintenance_asset_registrations',
            2
        );

        $this->assertSame(
            6,
            DB::table('maintenance_assets')
                ->where(
                    'source_goods_receipt_item_id',
                    $r['grn_item_id']
                )
                ->distinct()
                ->count('receipt_unit_ordinal')
        );

        $this->assertSame(
            $first->json('data.company_id'),
            $r['company_id']
        );
    }

    public function test_replay_returns_same_ids_and_same_quota_snapshot(): void
    {
        $this->signIn();

        $r = $this->receipt();

        $payload = $this->payload($r['grn_item_id']);

        $original = $this->postJson(
            self::REGISTER,
            $payload
        )->assertCreated()->json('data');

        $replayed = $this->postJson(
            self::REGISTER,
            $payload
        )->assertOk()->json('data');

        ksort($original);
        ksort($replayed);

        $this->assertSame($original, $replayed);

        $this->assertDatabaseCount('maintenance_assets', 2);

        $this->assertDatabaseCount(
            'maintenance_asset_registrations',
            1
        );
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $this->signIn();

        $r = $this->receipt();

        $payload = $this->payload($r['grn_item_id']);

        $this->postJson(
            self::REGISTER,
            $payload
        )->assertCreated();

        $payload['units'][0]['asset_name'] = 'Laptop diganti';

        $this->postJson(
            self::REGISTER,
            $payload
        )->assertStatus(409);

        $this->assertDatabaseCount('maintenance_assets', 2);
    }

    public function test_damaged_units_cannot_be_registered(): void
    {
        $this->signIn();

        $r = $this->receipt(
            received: '7.000',
            damaged: '1.000'
        );

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 6)
        )->assertCreated();

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 1)
        )->assertStatus(409);

        $this->assertDatabaseCount('maintenance_assets', 6);
    }

    public function test_fractional_usable_units_are_rejected(): void
    {
        $this->signIn();

        $r = $this->receipt(
            received: '6.500'
        );

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 1)
        )->assertStatus(409);

        $this->assertDatabaseCount('maintenance_assets', 0);
    }

    public function test_unclassified_item_is_rejected(): void
    {
        $this->signIn();

        $r = $this->receipt(
            eligible: false
        );

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 1)
        )->assertStatus(409);

        $this->assertDatabaseCount('maintenance_assets', 0);
    }

    public function test_missing_po_company_and_missing_prefix_are_rejected(): void
    {
        $this->signIn();

        $noCompany = $this->receipt(
            withCompany: false
        );

        $this->postJson(
            self::REGISTER,
            $this->payload($noCompany['grn_item_id'])
        )->assertStatus(409);

        $noPrefix = $this->receipt(
            withPrefix: false
        );

        $this->postJson(
            self::REGISTER,
            $this->payload($noPrefix['grn_item_id'])
        )->assertStatus(409);

        $this->assertDatabaseCount('maintenance_assets', 0);

        $this->assertDatabaseCount(
            'maintenance_asset_registrations',
            0
        );
    }

    public function test_registration_does_not_change_stock_or_order(): void
    {
        $this->signIn();

        $r = $this->receipt();

        $stock = (string) DB::table('items')
            ->where('id', $r['item_id'])
            ->value('stock_quantity');

        $movementCount = DB::table('stock_movements')->count();

        $orderStatus = DB::table('orders')
            ->where('id', $r['order_id'])
            ->value('status');

        $this->postJson(
            self::REGISTER,
            $this->payload($r['grn_item_id'], 1)
        )->assertCreated();

        $this->assertSame(
            $stock,
            (string) DB::table('items')
                ->where('id', $r['item_id'])
                ->value('stock_quantity')
        );

        $this->assertSame(
            $movementCount,
            DB::table('stock_movements')->count()
        );

        $this->assertSame(
            $orderStatus,
            DB::table('orders')
                ->where('id', $r['order_id'])
                ->value('status')
        );
    }

    public function test_cannot_set_company_or_asset_code_from_request(): void
    {
        $this->signIn();

        $r = $this->receipt();

        $payload = $this->payload($r['grn_item_id']);

        $payload['company_id'] = 999;
        $payload['asset_code'] = 'FAKE';

        $this->postJson(
            self::REGISTER,
            $payload
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_id',
                'asset_code',
            ]);

        $this->assertDatabaseCount('maintenance_assets', 0);
    }
}
