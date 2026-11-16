<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->decimal('maximum_stock', 18, 3)->unsigned()->nullable();
            $table->decimal('reference_price', 18, 2)->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn(['maximum_stock', 'reference_price']);
        });
    }
};
