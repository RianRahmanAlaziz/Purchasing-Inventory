<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 100);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 18, 2)->unsigned();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['supplier_id', 'invoice_number']);
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_number', 60)->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending_verification');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 60)->unique();
            $table->string('document_type', 150);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->date('document_date');
            $table->string('status', 30)->default('not_uploaded');
            $table->string('file_path')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            foreach (['sent', 'received', 'verified'] as $stage) {
                $table->timestamp($stage.'_at')->nullable();
                $table->foreignId($stage.'_by')->nullable()->constrained('users')->restrictOnDelete();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
