<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('location_key');
            $table->decimal('quantity', 18, 3)->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'location_key']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balance_nonnegative CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_location_matches CHECK (location_key = COALESCE(company_id, 0))');
        }
        Schema::table('items', function (Blueprint $table): void {
            $table->string('item_type', 30)->default('consumable');
        });
        foreach (['goods_receipts', 'distributions', 'stock_adjustments', 'stock_movements'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        Schema::table('distribution_items', function (Blueprint $table): void {
            $table->json('asset_ids')->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('from_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('to_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('transfer_item_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('operation_key', 100)->nullable()->unique();
            $table->unique(['transfer_item_id', 'movement_type']);
        });
        // Historical issuer PT is not evidence of the physical stock location.
        DB::statement('INSERT INTO inventory_balances (item_id, company_id, location_key, quantity, created_at, updated_at)
            SELECT id, NULL, 0, stock_quantity, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM items');
    }

    public function down(): void
    {
        foreach (['goods_receipts', 'distributions', 'stock_adjustments'] as $name) {
            if (DB::table($name)->whereNotNull('company_id')->exists()) {
                throw new RuntimeException('Rollback ditolak: dokumen sudah menyimpan PT operasional. Pertahankan metadata lokasi melalui forward fix.');
            }
        }
        if (DB::table('items')->where('item_type', 'office_asset')->exists()) {
            throw new RuntimeException('Rollback ditolak: klasifikasi aset sudah digunakan.');
        }
        if (DB::table('stock_movements')->whereNotNull('company_id')->orWhereNotNull('operation_key')->exists()
            || DB::table('inventory_balances')->whereNotNull('company_id')->where('quantity', '>', 0)->exists()) {
            throw new RuntimeException('Rollback ditolak: stok per PT sudah digunakan. Pulihkan backup yang konsisten atau lakukan forward fix.');
        }
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['from_company_id']);
            $table->dropForeign(['to_company_id']);
            $table->dropUnique(['transfer_item_id', 'movement_type']);
            $table->dropUnique(['operation_key']);
            $table->dropColumn(['from_company_id', 'to_company_id', 'transfer_item_id', 'asset_id', 'operation_key']);
        });
        Schema::table('distribution_items', fn (Blueprint $table) => $table->dropColumn('asset_ids'));
        foreach (['goods_receipts', 'distributions', 'stock_adjustments', 'stock_movements'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('item_type'));
        Schema::dropIfExists('inventory_balances');
    }
};
