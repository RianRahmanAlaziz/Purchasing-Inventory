<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->string('employee_number', 50)->unique();
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['department_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
