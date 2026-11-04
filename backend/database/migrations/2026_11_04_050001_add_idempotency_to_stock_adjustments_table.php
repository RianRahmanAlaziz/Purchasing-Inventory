<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_fingerprint']);
        });
    }
};
