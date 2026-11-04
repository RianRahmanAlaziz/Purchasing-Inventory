<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribution_id')->constrained('distributions')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['distribution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_events');
    }
};
