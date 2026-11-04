<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class StockServiceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);
    }

    private function actor(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function item(): Item
    {
        $category = Category::query()->create([
            'code' => 'STK', 'name' => 'Stock Test', 'is_active' => true,
        ]);

        return Item::query()->create([
            'category_id' => $category->id,
            'sku' => 'STK-002',
            'name' => 'Kertas HVS',
            'unit' => 'rim',
            'minimum_stock' => '2.000',
            'is_active' => true,
        ]);
    }

    /** Membuat PO + GRN data uji, bukan mengimplementasikan approval. */
    private function grnItem(Item $item, User $actor, string $status, string $received = '10.000', string $damaged = '2.500'): int
    {
        $department = Department::query()->create([
            'code' => 'OPS', 'name' => 'Operations', 'is_active' => true,
        ]);
        $employee = Employee::query()->create([
            'department_id' => $department->id,
            'employee_number' => 'EMP-STK-1',
            'name' => 'Operator Stock',
            'is_active' => true,
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-STK',
            'name' => 'Supplier Stock',
            'normalized_name' => 'supplier stock',
            'is_active' => true,
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-STOCK-001',
            'supplier_id' => $supplier->id,
            'requested_for_employee_id' => $employee->id,
            'requested_for_department_id' => $department->id,
            'created_by' => $actor->id,
            'supplier_name_snapshot' => $supplier->name,
            'supplier_code_snapshot' => $supplier->code,
            'order_date' => now()->toDateString(),
            'status' => 'sent',
        ]);
        $poItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'item_id' => $item->id,
            'item_sku_snapshot' => $item->sku,
            'item_name_snapshot' => $item->name,
            'unit_snapshot' => $item->unit,
            'quantity' => '10.000',
            'unit_price' => '12500.00',
            'line_subtotal' => '125000.00',
        ]);
        $grnId = DB::table('goods_receipts')->insertGetId([
            'grn_number' => 'GRN-STOCK-001',
            'purchase_order_id' => $poId,
            'created_by' => $actor->id,
            'confirmed_by' => $status === 'pending' ? null : $actor->id,
            'received_date' => now()->toDateString(),
            'status' => $status,
            'confirmed_at' => $status === 'pending' ? null : now(),
        ]);

        return DB::table('goods_receipt_items')->insertGetId([
            'goods_receipt_id' => $grnId,
            'purchase_order_item_id' => $poItemId,
            'qty_received' => $received,
            'qty_damaged' => $damaged,
        ]);
    }

    private function distributionItem(Item $item, User $actor, string $status, string $qty = '3.000'): int
    {
        $department = Department::query()->create([
            'code' => 'OPS', 'name' => 'Operations', 'is_active' => true,
        ]);
        $employee = Employee::query()->create([
            'department_id' => $department->id,
            'employee_number' => 'EMP-STK-2',
            'name' => 'Penerima Distribusi',
            'is_active' => true,
        ]);
        $distributionId = DB::table('distributions')->insertGetId([
            'distribution_number' => 'DST-STOCK-001',
            'employee_id' => $employee->id,
            'requested_by' => $actor->id,
            'sent_by' => $status === 'sent' ? $actor->id : null,
            'status' => $status,
            'purpose' => 'Kebutuhan ATK',
            'requested_at' => now(),
            'sent_at' => $status === 'sent' ? now() : null,
        ]);

        return DB::table('distribution_items')->insertGetId([
            'distribution_id' => $distributionId,
            'item_id' => $item->id,
            'quantity' => $qty,
        ]);
    }

    public function test_fixed_point_math_is_precise_and_bounds_are_checked(): void
    {
        $this->assertSame(1250, StockDecimal::parse('1.250'));
        $this->assertSame(-25, StockDecimal::parse('-0.025', true));
        $this->assertSame('1.225', StockDecimal::format(1225));
        $this->assertSame('999999999999999.999', StockDecimal::format(StockDecimal::MAX_MILLI));
    }

    public function test_service_rollback_removes_balance_and_audit_mutation(): void
    {
        $actor = $this->actor();
        $item = $this->item();
        $stock = app(StockService::class);

        try {
            DB::transaction(function () use ($actor, $item, $stock): void {
                $stock->adjustTo(
                    $item->id, '0.000', '6.125', 'Koreksi stok fisik',
                    $actor->id, (string) Str::uuid()
                );

                throw new RuntimeException('Paksa rollback transaksi');
            });
            $this->fail('Seharusnya transaksi rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Paksa rollback transaksi', $exception->getMessage());
        }

        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('stock_adjustments')->where('item_id', $item->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('item_id', $item->id)->count());
    }

    public function test_pending_grn_does_not_increase_stock(): void
    {
        $actor = $this->actor();
        $item = $this->item();
        $grnItemId = $this->grnItem($item, $actor, 'pending');

        try {
            app(StockService::class)->receiveConfirmedGrnItem($grnItemId, $actor->id);
            $this->fail('GRN Pending seharusnya ditolak.');
        } catch (StockConflictException $exception) {
            $this->assertStringContainsString('belum dikonfirmasi', $exception->getMessage());
        }

        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('stock_movements')->where('item_id', $item->id)->count());
    }

    public function test_confirmed_grn_adds_received_minus_damaged_once_and_updates_last_price(): void
    {
        $actor = $this->actor();
        $item = $this->item();
        $grnItemId = $this->grnItem($item, $actor, 'partially_received');
        $stock = app(StockService::class);

        $first = $stock->receiveConfirmedGrnItem($grnItemId, $actor->id);
        $second = $stock->receiveConfirmedGrnItem($grnItemId, $actor->id);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('7.500', (string) $item->fresh()->stock_quantity);
        $this->assertSame('12500.00', (string) $item->fresh()->last_purchase_price);
        $this->assertDatabaseHas('stock_movements', [
            'goods_receipt_item_id' => $grnItemId,
            'movement_type' => 'grn_in',
            'quantity_change' => '7.500',
        ]);
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $item->id)->count());
    }

    public function test_grn_all_damaged_does_not_change_stock(): void
    {
        $actor = $this->actor();
        $item = $this->item();
        $grnItemId = $this->grnItem($item, $actor, 'discrepancy', '5.000', '5.000');

        $this->assertNull(app(StockService::class)->receiveConfirmedGrnItem($grnItemId, $actor->id));
        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('stock_movements')->where('item_id', $item->id)->count());
    }

    public function test_requested_distribution_cannot_reduce_stock(): void
    {
        $actor = $this->actor();
        $item = $this->item();
        $distributionItemId = $this->distributionItem($item, $actor, 'requested');

        try {
            app(StockService::class)->issueSentDistributionItem($distributionItemId, $actor->id);
            $this->fail('Distribusi Requested seharusnya ditolak.');
        } catch (StockConflictException $exception) {
            $this->assertStringContainsString('belum dikirim', $exception->getMessage());
        }

        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
    }

    public function test_sent_distribution_reduces_stock_once(): void
    {
        $actor = $this->actor();
        $item = $this->item();
        $stock = app(StockService::class);
        $stock->adjustTo($item->id, '0.000', '5.000', 'Stok hasil opname', $actor->id, (string) Str::uuid());
        $distributionItemId = $this->distributionItem($item, $actor, 'sent');

        $first = $stock->issueSentDistributionItem($distributionItemId, $actor->id);
        $second = $stock->issueSentDistributionItem($distributionItemId, $actor->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('2.000', (string) $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'distribution_item_id' => $distributionItemId,
            'movement_type' => 'distribution_out',
            'quantity_change' => '-3.000',
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('distribution_item_id', $distributionItemId)->count());
    }

    public function test_insufficient_stock_rejects_sent_distribution_without_audit_row(): void
    {
        $actor = $this->actor();
        $item = $this->item();
        $distributionItemId = $this->distributionItem($item, $actor, 'sent');

        try {
            app(StockService::class)->issueSentDistributionItem($distributionItemId, $actor->id);
            $this->fail('Stok kurang seharusnya ditolak.');
        } catch (StockConflictException $exception) {
            $this->assertStringContainsString('tidak mencukupi', $exception->getMessage());
        }

        $this->assertSame('0.000', (string) $item->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('stock_movements')->where('item_id', $item->id)->count());
    }
}
