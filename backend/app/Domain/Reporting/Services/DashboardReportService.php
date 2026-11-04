<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Proyeksi read-only. Semua kuantitas beda satuan tidak pernah dijumlahkan.
 * Rentang tanggal berlaku untuk tanggal dokumen / tanggal kejadian sebagaimana
 * ditandai date_basis per bagian. Snapshot inventory selalu kondisi saat ini.
 */
final class DashboardReportService
{
    private const PO_STATUSES = [
        'draft', 'pending_approval', 'approved', 'sent',
        'received_by_supplier', 'rejected', 'cancelled',
    ];

    private const ORDER_STATUSES = [
        'pending', 'confirmed', 'partially_received', 'completed', 'cancelled',
    ];

    private const GRN_STATUSES = [
        'pending', 'fully_received', 'partially_received', 'discrepancy',
    ];

    private const DISTRIBUTION_STATUSES = [
        'requested', 'approved', 'preparing', 'sent',
        'received_by_employee', 'cancelled',
    ];

    private const MOVEMENT_TYPES = ['adjustment', 'grn_in', 'distribution_out'];

    /** @return array<string, mixed> */
    public function overview(User $actor, array $filters): array
    {
        $sections = [];

        if ($actor->can('po.view')) {
            $sections['purchase_orders'] = $this->purchaseOrders($filters);
        }
        if ($actor->can('orders.view')) {
            $sections['orders'] = $this->orders($filters);
        }
        if ($actor->can('grn.view')) {
            $sections['goods_receipts'] = $this->goodsReceipts($filters);
        }
        if ($actor->can('inventory.view')) {
            $sections['inventory'] = $this->inventory($filters);
        }
        if ($actor->can('distribution.view')) {
            $sections['distributions'] = $this->distributions($filters);
        }

        return [
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'available_sections' => array_keys($sections),
            'sections' => $sections,
        ];
    }

    /** @return array<string, mixed> */
    private function purchaseOrders(array $filters): array
    {
        $query = DB::table('purchase_orders')->whereNull('deleted_at');
        $this->range($query, 'order_date', $filters, dateOnly: true);

        $counts = $this->statusCounts($query, self::PO_STATUSES);

        return [
            ...$counts,
            'date_basis' => 'order_date',
            'total_amount_all_statuses' => $this->moneySum($query, 'total_amount'),
            'approved_or_later_amount' => $this->moneySum(
                (clone $query)->whereIn('status', [
                    'approved', 'sent', 'received_by_supplier',
                ]),
                'total_amount'
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function orders(array $filters): array
    {
        $query = DB::table('orders');
        $this->range($query, 'created_at', $filters);

        return [
            ...$this->statusCounts($query, self::ORDER_STATUSES),
            'date_basis' => 'created_at',
        ];
    }

    /** @return array<string, mixed> */
    private function goodsReceipts(array $filters): array
    {
        $query = DB::table('goods_receipts');
        $this->range($query, 'received_date', $filters, dateOnly: true);

        return [
            ...$this->statusCounts($query, self::GRN_STATUSES),
            'confirmed_count' => (clone $query)->whereNotNull('confirmed_at')->count(),
            'date_basis' => 'received_date',
        ];
    }

    /** @return array<string, mixed> */
    private function inventory(array $filters): array
    {
        $movements = DB::table('stock_movements');
        $this->range($movements, 'created_at', $filters);
        $movementCounts = $this->statusCounts(
            $movements, self::MOVEMENT_TYPES, 'movement_type'
        );

        return [
            'active_items' => DB::table('items')->where('is_active', true)->count(),
            'low_stock_items' => DB::table('items')
                ->where('is_active', true)
                ->whereColumn('stock_quantity', '<=', 'minimum_stock')
                ->count(),
            'movement_count' => $movementCounts['total'],
            'movement_by_type' => $movementCounts['by_status'],
            'date_basis' => [
                'stock_snapshot' => 'current',
                'movements' => 'created_at',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function distributions(array $filters): array
    {
        $query = DB::table('distributions');
        $this->range($query, 'requested_at', $filters);

        return [
            ...$this->statusCounts($query, self::DISTRIBUTION_STATUSES),
            'date_basis' => 'requested_at',
        ];
    }

    /** @param list<string> $knownStatuses */
    private function statusCounts(
        Builder $query,
        array $knownStatuses,
        string $column = 'status'
    ): array {
        $counts = array_fill_keys($knownStatuses, 0);
        $rows = (clone $query)
            ->select($column)
            ->selectRaw('COUNT(*) AS total')
            ->groupBy($column)
            ->get();

        foreach ($rows as $row) {
            $value = (string) $row->{$column};
            $counts[$value] = (int) $row->total;
        }

        return [
            'total' => array_sum($counts),
            'by_status' => $counts,
        ];
    }

    private function moneySum(Builder $query, string $column): string
    {
        // Nama kolom hanya konstanta internal, bukan input pengguna.
        $result = (clone $query)
            ->selectRaw("CAST(COALESCE(SUM({$column}), 0) AS DECIMAL(20,2)) AS amount")
            ->first();

        return (string) ($result->amount ?? '0.00');
    }

    private function range(
        Builder $query,
        string $column,
        array $filters,
        bool $dateOnly = false
    ): void {
        if (! isset($filters['from'], $filters['to'])) {
            return;
        }

        if ($dateOnly) {
            $query->whereBetween($column, [$filters['from'], $filters['to']]);

            return;
        }

        $query->whereBetween($column, [
            $filters['from'].' 00:00:00',
            $filters['to'].' 23:59:59',
        ]);
    }
}
