<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('sku', 80)->unique();
            $table->string('name', 200);
            $table->string('unit', 30);
            $table->decimal('stock_quantity', 18, 3)->unsigned()->default(0);
            $table->decimal('minimum_stock', 18, 3)->unsigned()->default(0);
            $table->decimal('last_purchase_price', 18, 2)->unsigned()->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
