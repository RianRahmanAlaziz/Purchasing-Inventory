<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->restrictOnDelete();
            $table->string('tax_code_snapshot', 50);
            $table->string('tax_name_snapshot', 160);
            $table->string('direction_snapshot', 20);
            $table->decimal('rate_percent_snapshot', 9, 4)->unsigned();
            $table->decimal('base_factor_snapshot', 12, 8)->unsigned();
            $table->decimal('taxable_base_amount', 18, 2)->unsigned();
            $table->decimal('tax_amount', 18, 2)->unsigned();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'tax_rate_id'], 'po_tax_rate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_taxes');
    }
};
