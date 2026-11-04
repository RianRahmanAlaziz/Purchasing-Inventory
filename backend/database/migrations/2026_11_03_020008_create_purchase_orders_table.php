<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('po_number', 60)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('requested_for_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('requested_for_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('supplier_name_snapshot', 200);
            $table->string('supplier_code_snapshot', 40);
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 40)->default('draft');
            $table->decimal('subtotal', 18, 2)->unsigned()->default(0);
            $table->decimal('tax_addition_total', 18, 2)->unsigned()->default(0);
            $table->decimal('tax_withholding_total', 18, 2)->unsigned()->default(0);
            $table->decimal('total_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('approval_basis_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('approval_threshold_amount', 18, 2)->unsigned()->default(7000000);
            $table->boolean('requires_gm_approval')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('supplier_confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'order_date']);
            $table->index(['supplier_id', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
