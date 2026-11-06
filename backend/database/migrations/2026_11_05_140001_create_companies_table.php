<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->longText('logo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('default_company_id')->nullable()->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_company_id'));
        Schema::dropIfExists('companies');
    }
};
