<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_asset_registrations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            $table->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->restrictOnDelete();

            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();

            $table->uuid('idempotency_key')->unique();

            $table->char('request_fingerprint', 64);
            $table->unsignedSmallInteger('unit_count');
            $table->json('response_payload')->nullable();

            $table->timestamps();

            $table->index(
                ['goods_receipt_item_id', 'id'],
                'maint_reg_grn_item_idx'
            );
        });

        Schema::table('maintenance_assets', function (Blueprint $table): void {
            $table->foreignId('maintenance_asset_registration_id')
                ->nullable()
                ->constrained('maintenance_asset_registrations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId(
                'maintenance_asset_registration_id'
            );
        });

        Schema::dropIfExists('maintenance_asset_registrations');
    }
};
