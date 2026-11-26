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
            'maintenance_asset_change_events',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('asset_id')->constrained('maintenance_assets')->restrictOnDelete();
                $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();

                $table->json('before_data');
                $table->json('after_data');

                $table->text('reason');

                $table->timestamp('created_at')->useCurrent();

                $table->index([
                    'asset_id',
                    'created_at',
                ], 'maint_asset_events_history_idx');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'maintenance_asset_change_events'
        );
    }
};
