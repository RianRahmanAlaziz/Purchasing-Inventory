<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribution_id')->constrained('distributions')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->unsigned();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['distribution_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_items');
    }
};
