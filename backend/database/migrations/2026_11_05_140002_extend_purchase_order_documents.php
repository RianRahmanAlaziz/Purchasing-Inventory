<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->json('company_snapshot')->nullable();
            $table->text('supplier_address_snapshot')->nullable();
            $table->decimal('discount_total', 18, 2)->unsigned()->default(0);
            $table->string('tax_scope', 10)->default('order');
        });
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('discount_percent', 9, 4)->unsigned()->default(0);
            $table->decimal('discount_amount', 18, 2)->unsigned()->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->string('tax_label_snapshot', 160)->nullable();
            $table->string('tax_direction_snapshot', 20)->nullable();
            $table->decimal('tax_rate_percent_snapshot', 9, 4)->unsigned()->nullable();
            $table->decimal('tax_base_factor_snapshot', 12, 8)->unsigned()->nullable();
            $table->decimal('taxable_base_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_rate_id');
            $table->dropColumn(['discount_percent', 'discount_amount', 'tax_label_snapshot',
                'tax_direction_snapshot', 'tax_rate_percent_snapshot', 'tax_base_factor_snapshot',
                'taxable_base_amount', 'tax_amount']);
        });
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['company_snapshot', 'supplier_address_snapshot', 'discount_total', 'tax_scope']);
        });
    }
};
