<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_meter_readings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('vehicle_profile_id')
                ->constrained('maintenance_vehicle_profiles')
                ->restrictOnDelete();

            // odometer_km atau hourmeter_hours. "both" adalah mode profil,
            // bukan jenis satu pembacaan.
            $table->string('meter_type', 24);
            $table->decimal('reading_value', 18, 3)->unsigned();
            $table->dateTime('measured_at');
            $table->string('source', 30)->default('manual');
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('corrected_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('corrected_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['vehicle_profile_id', 'meter_type', 'measured_at'],
                'maint_meter_readings_time_unique'
            );
            $table->index(
                ['vehicle_profile_id', 'measured_at'],
                'maint_meter_readings_history_idx'
            );
        });

        Schema::create('maintenance_meter_reading_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('meter_reading_id')
                ->constrained('maintenance_meter_readings')
                ->restrictOnDelete();
            $table->foreignId('changed_by')
                ->constrained('users')
                ->restrictOnDelete();

            // recorded atau corrected (divalidasi di Action pada R1-009).
            $table->string('action', 20);
            $table->decimal('before_value', 18, 3)->unsigned()->nullable();
            $table->decimal('after_value', 18, 3)->unsigned();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['meter_reading_id', 'created_at'],
                'maint_meter_reading_events_history_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_meter_reading_events');
        Schema::dropIfExists('maintenance_meter_readings');
    }
};
