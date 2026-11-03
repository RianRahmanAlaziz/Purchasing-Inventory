<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('goods_receipt_item_id')->nullable()->unique()->constrained('goods_receipt_items')->restrictOnDelete();
            $table->foreignId('distribution_item_id')->nullable()->unique()->constrained('distribution_items')->restrictOnDelete();
            $table->foreignId('stock_adjustment_id')->nullable()->unique()->constrained('stock_adjustments')->restrictOnDelete();
            $table->string('movement_type', 20);
            $table->decimal('quantity_change', 18, 3);
            $table->decimal('balance_before', 18, 3)->unsigned();
            $table->decimal('balance_after', 18, 3)->unsigned();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
