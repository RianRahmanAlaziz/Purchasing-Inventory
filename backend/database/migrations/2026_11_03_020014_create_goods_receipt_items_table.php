<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->decimal('qty_received', 18, 3)->unsigned();
            $table->decimal('qty_damaged', 18, 3)->unsigned()->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['goods_receipt_id', 'purchase_order_item_id'], 'grn_po_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
