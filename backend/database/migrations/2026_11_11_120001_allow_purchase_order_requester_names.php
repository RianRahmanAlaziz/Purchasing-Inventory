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
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_for_employee_id')->nullable()->change();
            $table->unsignedBigInteger('requested_for_department_id')->nullable()->change();
            $table->string('requested_for_employee_name_snapshot', 200)->nullable();
            $table->string('requested_for_department_name_snapshot', 200)->nullable();
        });

        DB::table('purchase_orders')->whereNotNull('requested_for_employee_id')->update([
            'requested_for_employee_name_snapshot' => DB::raw('(select name from employees where employees.id = purchase_orders.requested_for_employee_id)'),
        ]);
        DB::table('purchase_orders')->whereNotNull('requested_for_department_id')->update([
            'requested_for_department_name_snapshot' => DB::raw('(select name from departments where departments.id = purchase_orders.requested_for_department_id)'),
        ]);
    }

    public function down(): void
    {
        if (DB::table('purchase_orders')
            ->whereNull('requested_for_employee_id')
            ->orWhereNull('requested_for_department_id')
            ->orWhereRaw('requested_for_employee_name_snapshot <> (select name from employees where employees.id = purchase_orders.requested_for_employee_id)')
            ->orWhereRaw('requested_for_department_name_snapshot <> (select name from departments where departments.id = purchase_orders.requested_for_department_id)')
            ->exists()) {
            throw new RuntimeException('Rollback akan menghilangkan nama pemohon atau departemen pada PO.');
        }

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_for_employee_id')->nullable(false)->change();
            $table->unsignedBigInteger('requested_for_department_id')->nullable(false)->change();
            $table->dropColumn(['requested_for_employee_name_snapshot', 'requested_for_department_name_snapshot']);
        });
    }
};
