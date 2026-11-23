<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('distributions', 'recipient_name_snapshot')) {
            Schema::table('distributions', function (Blueprint $table): void {
                $table->string('recipient_name_snapshot', 200)->nullable()->after('employee_id');
            });
        }
        Schema::table('distributions', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
        });

        DB::statement('UPDATE distributions SET recipient_name_snapshot = (
            SELECT name FROM employees WHERE employees.id = distributions.employee_id
        ) WHERE recipient_name_snapshot IS NULL');
    }

    public function down(): void
    {
        if (DB::table('distributions')->whereNull('employee_id')->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada distribusi dengan penerima bebas. Data penerima harus dipertahankan.');
        }

        DB::table('distributions')->where('status', 'completed')->update(['status' => 'sent']);

        Schema::table('distributions', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
            $table->dropColumn('recipient_name_snapshot');
        });
    }
};
