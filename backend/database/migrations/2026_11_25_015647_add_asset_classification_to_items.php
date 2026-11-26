<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_asset_trackable')->default(false)->after('is_active');
        });

        Schema::create('maintenance_item_classification_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('item_sku_snapshot', 80);

            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();

            $table->boolean('before_value');
            $table->boolean('after_value');
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_item_classification_events');

        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('is_asset_trackable');
        });
    }
};
