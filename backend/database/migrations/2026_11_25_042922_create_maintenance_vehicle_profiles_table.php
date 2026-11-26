<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'maintenance_vehicle_profiles',
            function (Blueprint $table): void {
                $table->id();

                // Satu aset fisik maksimal satu profil.
                $table->foreignId('asset_id')->unique()->constrained('maintenance_assets')->restrictOnDelete();

                // Identitas operasional.
                $table->string('fleet_number', 60)->nullable();
                $table->string('registration_number', 30)->nullable();

                $table->string('brand', 100)->nullable();
                $table->string('model', 120)->nullable();
                $table->unsignedSmallInteger('manufacture_year')->nullable();

                $table->string('chassis_number', 120)->nullable();
                $table->string('engine_number', 120)->nullable();

                // Mode pembacaan:
                // odometer_km | hourmeter_hours | both
                // Validasi nilainya dilakukan pada API tahap R1-007.
                $table->string('meter_mode', 24);
                $table->text('notes')->nullable();

                // Audit pembuat dan pengubah terakhir.
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

                $table->timestamps();

                $table->index(
                    'registration_number',
                    'maint_vehicle_plate_idx'
                );

                $table->index(
                    'fleet_number',
                    'maint_vehicle_fleet_idx'
                );

                $table->index(
                    'meter_mode',
                    'maint_vehicle_meter_mode_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'maintenance_vehicle_profiles'
        );
    }
};
