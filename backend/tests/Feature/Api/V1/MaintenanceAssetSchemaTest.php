<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Company\Models\Company;
use App\Domain\Item\Models\Item;
use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaintenanceAssetSchemaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name
        );
    }

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'PT TEST ' . Str::upper(Str::random(10)),
        ]);
    }

    private function createAsset(
        Company $company,
        User $registrar,
        int $sequence,
        ?string $code = null,
        ?int $receiptItemId = null,
        ?int $ordinal = null,
    ): MaintenanceAsset {
        $asset = new MaintenanceAsset();

        $asset->company_id = $company->id;
        $asset->sequence_number = $sequence;
        $asset->asset_code = $code
            ?? 'TEST-' . $company->id . '-' . str_pad(
                (string) $sequence,
                6,
                '0',
                STR_PAD_LEFT
            );

        $asset->asset_name = 'Laptop operasional';
        $asset->asset_type = 'office_equipment';

        $asset->registration_source = $receiptItemId === null
            ? 'legacy_manual'
            : 'confirmed_grn';

        $asset->source_goods_receipt_item_id = $receiptItemId;
        $asset->receipt_unit_ordinal = $ordinal;
        $asset->registered_by = $registrar->id;
        $asset->registered_at = now();

        $asset->save();

        return $asset->refresh();
    }

    /**
     * Fixture langsung di DB untuk menguji constraint schema.
     * Ini bukan pengganti workflow ConfirmGoodsReceipt.
     *
     * @return array{receipt_item_id: int, item: Item}
     */
    private function confirmedReceiptItem(
        Company $company,
        User $actor
    ): array {
        $category = Category::query()->create([
            'code' => 'MGA' . Str::upper(Str::random(8)),
            'name' => 'Uji Maintenance',
            'is_active' => true,
        ]);

        $item = Item::query()->create([
            'category_id' => $category->id,
            'sku' => 'MGA-' . Str::upper(Str::random(10)),
            'name' => 'Laptop untuk pengujian',
            'unit' => 'pcs',
            'minimum_stock' => '0.000',
            'is_active' => true,
        ]);

        $supplierCode = 'SUP-' . Str::upper(Str::random(8));

        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => $supplierCode,
            'name' => 'Supplier Uji Maintenance',
            'normalized_name' => strtolower($supplierCode),
            'is_active' => true,
        ]);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-' . Str::upper(Str::random(12)),
            'supplier_id' => $supplierId,
            'created_by' => $actor->id,
            'supplier_name_snapshot' => 'Supplier Uji Maintenance',
            'supplier_code_snapshot' => $supplierCode,
            'company_id' => $company->id,
            'order_date' => today()->toDateString(),
            'status' => 'sent',
        ]);

        $poItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'item_id' => $item->id,
            'item_sku_snapshot' => $item->sku,
            'item_name_snapshot' => $item->name,
            'unit_snapshot' => 'pcs',
            'quantity' => '6.000',
            'unit_price' => '100000.00',
            'line_subtotal' => '600000.00',
        ]);

        $receiptId = DB::table('goods_receipts')->insertGetId([
            'grn_number' => 'GRN-' . Str::upper(Str::random(12)),
            'purchase_order_id' => $poId,
            'created_by' => $actor->id,
            'confirmed_by' => $actor->id,
            'received_date' => today()->toDateString(),
            'status' => 'partially_received',
            'confirmed_at' => now(),
        ]);

        $receiptItemId = DB::table('goods_receipt_items')
            ->insertGetId([
                'goods_receipt_id' => $receiptId,
                'purchase_order_item_id' => $poItemId,
                'qty_received' => '6.000',
                'qty_damaged' => '0.000',
            ]);

        return [
            'receipt_item_id' => $receiptItemId,
            'item' => $item,
        ];
    }

    public function test_schema_and_company_relationship(): void
    {
        $this->assertTrue(Schema::hasTable('maintenance_assets'));
        $this->assertTrue(
            Schema::hasTable('maintenance_asset_sequences')
        );

        $company = $this->company();

        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $asset = $this->createAsset($company, $actor, 1);

        $this->assertSame(
            $company->id,
            $asset->company->id
        );

        $this->assertSame(
            $actor->id,
            $asset->registrar->id
        );

        $this->assertTrue($asset->is_active);
        $this->assertNull($asset->sourceGoodsReceiptItem);
    }

    public function test_sequence_numbers_are_unique_within_each_company(): void
    {
        $companyA = $this->company();
        $companyB = $this->company();

        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $this->createAsset($companyA, $actor, 1);
        $this->createAsset($companyB, $actor, 1);

        $this->assertSame(
            2,
            DB::table('maintenance_assets')
                ->where('sequence_number', 1)
                ->count()
        );
    }

    public function test_duplicate_sequence_in_same_company_is_rejected(): void
    {
        $company = $this->company();

        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $this->createAsset($company, $actor, 1);

        $this->expectException(QueryException::class);

        $this->createAsset(
            $company,
            $actor,
            1,
            'DIFFERENT-CODE-001'
        );
    }

    public function test_duplicate_code_across_companies_is_rejected(): void
    {
        $companyA = $this->company();
        $companyB = $this->company();

        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $this->createAsset(
            $companyA,
            $actor,
            1,
            'ASSET-EXAMPLE-001'
        );

        $this->expectException(QueryException::class);

        $this->createAsset(
            $companyB,
            $actor,
            1,
            'ASSET-EXAMPLE-001'
        );
    }

    public function test_duplicate_ordinal_on_same_grn_line_is_rejected(): void
    {
        $company = $this->company();

        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $receipt = $this->confirmedReceiptItem(
            $company,
            $actor
        );

        $first = $this->createAsset(
            $company,
            $actor,
            1,
            null,
            $receipt['receipt_item_id'],
            1
        );

        $this->assertSame(
            $receipt['receipt_item_id'],
            $first->sourceGoodsReceiptItem->id
        );

        $this->expectException(QueryException::class);

        $this->createAsset(
            $company,
            $actor,
            2,
            null,
            $receipt['receipt_item_id'],
            1
        );
    }

    public function test_registering_asset_does_not_mutate_inventory(): void
    {
        $company = $this->company();

        $actor = User::factory()->create([
            'is_active' => true,
        ]);

        $receipt = $this->confirmedReceiptItem(
            $company,
            $actor
        );

        $before = (string) $receipt['item']
            ->refresh()
            ->stock_quantity;

        $movementCount = DB::table('stock_movements')->count();

        $this->createAsset(
            $company,
            $actor,
            1,
            null,
            $receipt['receipt_item_id'],
            1
        );

        $this->assertSame(
            $before,
            (string) $receipt['item']
                ->refresh()
                ->stock_quantity
        );

        $this->assertSame(
            $movementCount,
            DB::table('stock_movements')->count()
        );
    }
}
