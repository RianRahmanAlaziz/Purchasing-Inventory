<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 60);
            $table->string('subject_type', 30);
            $table->string('subject_id', 64);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at'], 'rbac_audit_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_audit_logs');
    }
};
