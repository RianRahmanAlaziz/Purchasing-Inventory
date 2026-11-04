<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aktivitas lintas domain hanya dari sumber yang boleh dibaca actor.
 * Tidak mengambil notes/alasan, informasi personal, atau nilai finansial.
 */
final class DashboardActivityService
{
    /** @return array{data:array,meta:array} */
    public function paginate(User $actor, array $filters): array
    {
        $sources = $this->sources($actor, $filters);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $pageNo = (int) ($filters['page'] ?? 1);

        if ($sources === []) {
            return [
                'data' => [],
                'meta' => ['pagination' => [
                    'current_page' => $pageNo,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ]],
            ];
        }

        $union = array_shift($sources);
        foreach ($sources as $source) {
            $union->unionAll($source);
        }

        $result = DB::query()
            ->fromSub($union, 'activity_rows')
            ->orderByDesc('occurred_at')
            ->orderByDesc('source')
            ->orderByDesc('event_id')
            ->paginate($perPage, ['*'], 'page', $pageNo);

        $items = $result->getCollection()
            ->map(static fn (object $row): array => [
                'source' => (string) $row->source,
                'event_id' => (int) $row->event_id,
                'reference_id' => (int) $row->reference_id,
                'action' => (string) $row->action,
                'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'occurred_at' => (string) $row->occurred_at,
            ])
            ->values()
            ->all();

        return [
            'data' => $items,
            'meta' => ['pagination' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
            ]],
        ];
    }

    /** @return list<Builder> */
    private function sources(User $actor, array $filters): array
    {
        $sources = [];

        if ($actor->can('po.view')) {
            $po = DB::table('purchase_order_events AS e')
                ->join('purchase_orders AS p', 'p.id', '=', 'e.purchase_order_id')
                ->whereNull('p.deleted_at')
                ->selectRaw("'purchase_order' AS source, e.id AS event_id, e.purchase_order_id AS reference_id, e.action AS action, e.actor_id AS actor_id, e.created_at AS occurred_at");
            $this->range($po, 'e.created_at', $filters);
            $sources[] = $po;
        }

        if ($actor->can('grn.view')) {
            $grn = DB::table('goods_receipts AS gr')
                ->selectRaw("'goods_receipt' AS source, gr.id AS event_id, gr.id AS reference_id, gr.status AS action, COALESCE(gr.confirmed_by, gr.created_by) AS actor_id, COALESCE(gr.confirmed_at, gr.created_at) AS occurred_at");
            // GRN di timeline menampilkan waktu status saat ini, bukan audit semua transisi.
            $this->range($grn, 'COALESCE(gr.confirmed_at, gr.created_at)', $filters, true);
            $sources[] = $grn;
        }

        if ($actor->can('distribution.view')) {
            $distribution = DB::table('distribution_events AS e')
                ->selectRaw("'distribution' AS source, e.id AS event_id, e.distribution_id AS reference_id, e.action AS action, e.actor_id AS actor_id, e.created_at AS occurred_at");
            $this->range($distribution, 'e.created_at', $filters);
            $sources[] = $distribution;
        }

        if ($actor->can('inventory.view')) {
            $stock = DB::table('stock_movements AS m')
                ->selectRaw("'stock_movement' AS source, m.id AS event_id, m.item_id AS reference_id, m.movement_type AS action, m.created_by AS actor_id, m.created_at AS occurred_at");
            $this->range($stock, 'm.created_at', $filters);
            $sources[] = $stock;
        }

        return $sources;
    }

    private function range(
        Builder $builder,
        string $column,
        array $filters,
        bool $expression = false
    ): void {
        if (! isset($filters['from'], $filters['to'])) {
            return;
        }

        $from = $filters['from'].' 00:00:00';
        $to = $filters['to'].' 23:59:59';

        if ($expression) {
            // Ekspresi SQL statis, tanggal selalu query bindings.
            $builder->whereRaw("{$column} BETWEEN ? AND ?", [$from, $to]);

            return;
        }

        $builder->whereBetween($column, [$from, $to]);
    }
}
