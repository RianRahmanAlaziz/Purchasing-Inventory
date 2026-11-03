<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_type_id')->constrained('tax_types')->restrictOnDelete();
            $table->string('label', 160);
            $table->decimal('rate_percent', 9, 4)->unsigned();
            $table->decimal('taxable_base_factor', 12, 8)->unsigned()->default(1);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tax_type_id', 'is_active', 'effective_from'], 'tax_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
