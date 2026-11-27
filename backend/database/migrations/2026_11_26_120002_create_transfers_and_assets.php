<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_number', 60)->unique();
            $table->foreignId('from_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('to_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_transfer_id')->constrained('inventory_transfers')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->unsigned();
            $table->json('asset_ids')->nullable();
            $table->unique(['inventory_transfer_id', 'item_id']);
        });
        Schema::create('inventory_transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_transfer_id')->constrained('inventory_transfers')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('office_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_code', 60)->unique();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status', 20)->default('available');
            $table->string('condition', 20)->default('good');
            $table->string('holder_name', 200)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('source_unit')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'source_unit']);
            $table->index(['company_id', 'item_id', 'status']);
        });
        Schema::create('asset_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_asset_id')->constrained('office_assets')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('from_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('to_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('from_holder', 200)->nullable();
            $table->string('to_holder', 200)->nullable();
            $table->string('operation_key', 120)->unique();
            $table->string('request_fingerprint', 64)->nullable();
            $table->json('reference')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_transfers')->exists() || DB::table('office_assets')->exists()) {
            throw new RuntimeException('Rollback ditolak: transfer/aset sudah memiliki data. Arsipkan dan rekonsiliasi sebelum rollback.');
        }
        Schema::dropIfExists('asset_events');
        Schema::dropIfExists('office_assets');
        Schema::dropIfExists('inventory_transfer_events');
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
    }
};
