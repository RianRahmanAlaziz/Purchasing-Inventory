<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RevisedSchemaTest extends TestCase
{
    private function assertSafeDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());

        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name
        );
    }

    public function test_revised_tables_exist_without_finance_tables(): void
    {
        $this->assertSafeDatabase();

        foreach (
            [
                'users',
                'roles',
                'permissions',
                'model_has_roles',
                'model_has_permissions',
                'role_has_permissions',
                'departments',
                'employees',
                'suppliers',
                'categories',
                'items',
                'tax_types',
                'tax_rates',
                'purchase_orders',
                'purchase_order_items',
                'purchase_order_taxes',
                'purchase_order_approvals',
                'orders',
                'goods_receipts',
                'goods_receipt_items',
                'stock_adjustments',
                'distributions',
                'distribution_items',
                'stock_movements',
            ] as $table
        ) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Missing: {$table}"
            );
        }

        foreach (
            [
                'supplier_invoices',
                'payment_transactions',
                'financial_documents'
            ] as $table
        ) {
            $this->assertFalse(
                Schema::hasTable($table),
                "Deferred module exists: {$table}"
            );
        }
    }

    public function test_po_tax_and_approval_columns_exist(): void
    {
        $this->assertSafeDatabase();

        foreach (
            [
                'purchase_orders' => [
                    'requested_for_employee_id',
                    'requested_for_department_id',
                    'subtotal',
                    'tax_addition_total',
                    'tax_withholding_total',
                    'total_amount',
                    'approval_basis_amount',
                    'approval_threshold_amount',
                    'requires_gm_approval',
                ],
                'purchase_order_taxes' => [
                    'tax_rate_id',
                    'tax_code_snapshot',
                    'rate_percent_snapshot',
                    'taxable_base_amount',
                    'tax_amount',
                ],
                'purchase_order_approvals' => [
                    'step_order',
                    'role_name',
                    'status',
                    'decided_by',
                ],
                'stock_movements' => [
                    'goods_receipt_item_id',
                    'distribution_item_id',
                    'stock_adjustment_id',
                    'quantity_change',
                ],
            ] as $table => $columns
        ) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Missing {$table}.{$column}"
                );
            }
        }
    }

    public function test_po_approval_and_order_have_unique_keys(): void
    {
        $this->assertSafeDatabase();

        $this->assertUniqueIndex('orders', ['purchase_order_id']);

        $this->assertUniqueIndex(
            'purchase_order_approvals',
            ['purchase_order_id', 'step_order']
        );

        $this->assertUniqueIndex(
            'purchase_order_taxes',
            ['purchase_order_id', 'tax_rate_id']
        );

        $this->assertUniqueIndex(
            'stock_movements',
            ['goods_receipt_item_id']
        );

        $this->assertUniqueIndex(
            'stock_movements',
            ['distribution_item_id']
        );

        $this->assertUniqueIndex(
            'stock_movements',
            ['stock_adjustment_id']
        );
    }

    /** @param list<string> $columns */
    private function assertUniqueIndex(string $table, array $columns): void
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}`");
        $groups = collect($rows)->groupBy('Key_name');

        $matches = $groups->contains(function ($entries) use ($columns): bool {
            $ordered = $entries->sortBy('Seq_in_index');

            return (int) $ordered->first()->Non_unique === 0
                && $ordered->pluck('Column_name')->values()->all() === $columns;
        });

        $this->assertTrue(
            $matches,
            "Missing unique index for {$table}: " . implode(', ', $columns)
        );
    }
}
