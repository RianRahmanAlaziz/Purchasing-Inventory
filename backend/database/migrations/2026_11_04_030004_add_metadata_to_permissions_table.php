<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->string('display_name', 150)->nullable();
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'description']);
        });
    }
};
