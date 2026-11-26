<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Counter nomor aset untuk masing-masing PT.
        Schema::create('maintenance_asset_sequences', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->primary();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();

            // Belum ditetapkan sampai format kode PT disetujui.
            $table->string('prefix', 20)->nullable()->unique();

            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        // Master aset: satu baris mewakili satu unit aset.
        Schema::create('maintenance_assets', function (Blueprint $table): void {
            $table->id();

            // PT pemilik aset.
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // Identitas dan penomoran aset.
            $table->unsignedBigInteger('sequence_number');
            $table->string('asset_code', 80)->unique();
            $table->string('asset_name', 200);
            $table->string('asset_type', 40);

            // Informasi fisik aset.
            $table->string('serial_number', 120)->nullable();
            $table->string('location', 200)->nullable();

            // Aset lama mungkin belum mempunyai referensi ERP.
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();

            // Asal aset dari baris GRN confirmed, bila tersedia.
            $table->foreignId('source_goods_receipt_item_id')->nullable()->constrained('goods_receipt_items')->restrictOnDelete();

            // Nomor unit pada baris GRN.
            $table->unsignedInteger('receipt_unit_ordinal')->nullable();

            // Nilai kandidat: confirmed_grn / legacy_manual.
            $table->string('registration_source', 30);

            // Audit aktor registrasi.
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('registered_at');

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Nomor urut tidak boleh duplikat dalam satu PT.
            $table->unique(
                ['company_id', 'sequence_number'],
                'maint_assets_company_sequence_unique'
            );

            // Satu unit GRN tidak boleh digunakan dua kali.
            $table->unique(
                [
                    'source_goods_receipt_item_id',
                    'receipt_unit_ordinal'
                ],
                'maint_assets_grn_ordinal_unique'
            );

            // Indeks pencarian aset berdasarkan perusahaan dan tipe.
            $table->index(
                ['company_id', 'asset_type', 'is_active'],
                'maint_assets_company_type_active_idx'
            );

            $table->index(
                ['item_id', 'company_id'],
                'maint_assets_item_company_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_assets');
        Schema::dropIfExists('maintenance_asset_sequences');
    }
};
