<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->decimal('stock_before', 18, 3)->unsigned();
            $table->decimal('stock_after', 18, 3)->unsigned();
            $table->decimal('quantity_difference', 18, 3);
            $table->text('reason');
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->index(['item_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
