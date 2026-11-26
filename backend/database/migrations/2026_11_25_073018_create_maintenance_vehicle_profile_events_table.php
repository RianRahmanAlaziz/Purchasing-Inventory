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
            'maintenance_vehicle_profile_events',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('vehicle_profile_id')->constrained('maintenance_vehicle_profiles')->restrictOnDelete();

                $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();

                $table->string('action', 20);

                $table->json('before_data');
                $table->json('after_data');

                $table->text('reason');
                $table->timestamp('created_at')->useCurrent();

                $table->index(
                    ['vehicle_profile_id', 'created_at'],
                    'maint_vehicle_events_history_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'maintenance_vehicle_profile_events'
        );
    }
};
