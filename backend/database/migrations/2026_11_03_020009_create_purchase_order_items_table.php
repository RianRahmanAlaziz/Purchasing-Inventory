<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('item_sku_snapshot', 80);
            $table->string('item_name_snapshot', 200);
            $table->string('unit_snapshot', 30);
            $table->decimal('quantity', 18, 3)->unsigned();
            $table->decimal('unit_price', 18, 2)->unsigned();
            $table->decimal('line_subtotal', 18, 2)->unsigned();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['purchase_order_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
