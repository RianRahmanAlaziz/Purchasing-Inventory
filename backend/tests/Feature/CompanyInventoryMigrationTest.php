<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Inventory\Support\StockDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CompanyInventoryMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        config(['database.connections.company_inventory_migration' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('company_inventory_migration');

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->decimal('stock_quantity', 18, 3);
        });
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies');
        });
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
        });
        foreach (['distributions', 'distribution_items', 'stock_adjustments'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
            });
        }
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('movement_type', 40);
        });

        DB::table('companies')->insert([['id' => 1, 'name' => 'PT GA'], ['id' => 2, 'name' => 'PT K35']]);
        DB::table('items')->insert([['id' => 1, 'stock_quantity' => '10.250'], ['id' => 2, 'stock_quantity' => '0.000']]);
        DB::table('purchase_orders')->insert(['id' => 1, 'company_id' => 1]);
        DB::table('goods_receipts')->insert(['id' => 1, 'purchase_order_id' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('company_inventory_migration');
        DB::setDefaultConnection('mysql');
        parent::tearDown();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_26_120001_add_company_inventory.php');
    }

    private function quantity(mixed $value): string
    {
        return StockDecimal::format(StockDecimal::parse((string) $value));
    }

    public function test_backfill_preserves_stock_in_unallocated_bucket_without_inventing_a_pt(): void
    {
        $this->migration()->up();

        $this->assertSame(2, DB::table('inventory_balances')->count());
        $this->assertSame(0, DB::table('inventory_balances')->whereNotNull('company_id')->count());
        $this->assertSame('10.250', $this->quantity(DB::table('inventory_balances')->sum('quantity')));
        $this->assertSame('10.250', $this->quantity(DB::table('items')->sum('stock_quantity')));
        $this->assertSame(0, DB::table('inventory_balances')->where('location_key', '!=', 0)->count());
        $this->assertSame(2, DB::table('companies')->count());
        $this->assertNull(DB::table('goods_receipts')->where('id', 1)->value('company_id'));
        $this->assertSame(1, DB::table('purchase_orders')->where('id', 1)->value('company_id'));
        $this->assertSame('consumable', DB::table('items')->where('id', 1)->value('item_type'));
    }

    public function test_unused_expansion_can_roll_back_without_losing_legacy_balance(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasTable('inventory_balances'));
        $this->assertFalse(Schema::hasColumn('items', 'item_type'));
        $this->assertSame('10.250', $this->quantity(DB::table('items')->sum('stock_quantity')));
        $this->assertSame(2, DB::table('items')->count());
        $this->assertSame(2, DB::table('companies')->count());
    }

    public function test_rollback_refuses_to_discard_stock_already_allocated_to_a_pt(): void
    {
        $migration = $this->migration();
        $migration->up();
        DB::table('inventory_balances')->where('item_id', 1)->update(['quantity' => '7.250']);
        DB::table('inventory_balances')->insert([
            'item_id' => 1, 'company_id' => 2, 'location_key' => 2, 'quantity' => '3.000',
        ]);

        try {
            $migration->down();
            $this->fail('Rollback harus menolak penghapusan alokasi stok per PT.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback ditolak', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('inventory_balances'));
        $this->assertSame('10.250', $this->quantity(DB::table('inventory_balances')->sum('quantity')));
        $this->assertSame('3.000', $this->quantity(DB::table('inventory_balances')->where('company_id', 2)->value('quantity')));
    }
}
