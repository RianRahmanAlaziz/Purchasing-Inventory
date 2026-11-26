<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Queries;

use App\Domain\Inventory\Support\StockDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ListAssetCandidates
{
    public function paginate(
        ?int $companyId,
        int $perPage = 15
    ): LengthAwarePaginator {
        $registered = DB::table('maintenance_assets')
            ->select('source_goods_receipt_item_id')
            ->selectRaw('COUNT(*) AS registered_units')
            ->whereNotNull('source_goods_receipt_item_id')
            ->groupBy('source_goods_receipt_item_id');

        $query = DB::table('goods_receipt_items as gri')
            ->join(
                'goods_receipts as gr',
                'gr.id',
                '=',
                'gri.goods_receipt_id'
            )
            ->join(
                'purchase_orders as po',
                'po.id',
                '=',
                'gr.purchase_order_id'
            )
            ->join(
                'purchase_order_items as poi',
                function ($join): void {
                    $join
                        ->on(
                            'poi.id',
                            '=',
                            'gri.purchase_order_item_id'
                        )
                        ->on(
                            'poi.purchase_order_id',
                            '=',
                            'po.id'
                        );
                }
            )
            ->join(
                'items as i',
                'i.id',
                '=',
                'poi.item_id'
            )
            ->join(
                'companies as c',
                'c.id',
                '=',
                'po.company_id'
            )
            ->leftJoinSub(
                $registered,
                'used',
                function ($join): void {
                    $join->on(
                        'used.source_goods_receipt_item_id',
                        '=',
                        'gri.id'
                    );
                }
            )
            ->whereNotNull('gr.confirmed_at')
            ->whereIn('gr.status', [
                'fully_received',
                'partially_received',
                'discrepancy',
            ])
            ->whereIn('po.status', [
                'sent',
                'received_by_supplier',
            ])
            ->whereNull('po.deleted_at')
            ->whereNull('c.deleted_at')
            ->where('i.is_active', true)
            ->where('i.is_asset_trackable', true)
            ->whereRaw(
                'gri.qty_received > gri.qty_damaged'
            )
            ->whereRaw(
                '(gri.qty_received - gri.qty_damaged) = FLOOR(gri.qty_received - gri.qty_damaged)'
            )
            ->whereRaw(
                '(gri.qty_received - gri.qty_damaged) > COALESCE(used.registered_units, 0)'
            )
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('stock_movements as sm')
                    ->whereColumn(
                        'sm.goods_receipt_item_id',
                        'gri.id'
                    )
                    ->where(
                        'sm.movement_type',
                        'grn_in'
                    );
            })
            ->select([
                'gri.id as goods_receipt_item_id',
                'gr.grn_number',
                'po.po_number',
                'po.company_id',
                'i.id as item_id',
                'i.sku',
                'i.unit',
                'gri.qty_received',
                'gri.qty_damaged',
            ])
            ->selectRaw(
                '(gri.qty_received - gri.qty_damaged) AS usable_qty'
            )
            ->selectRaw(
                'COALESCE(used.registered_units, 0) AS registered_units'
            );

        if ($companyId !== null) {
            $query->where(
                'po.company_id',
                $companyId
            );
        }

        $page = $query->orderBy('gri.id')->paginate($perPage);

        $page->setCollection(
            $page->getCollection()->map(
                static function ($row): array {
                    $usable = intdiv(
                        StockDecimal::parse(
                            (string) $row->usable_qty
                        ),
                        1000
                    );

                    $registeredCount = (int) $row->registered_units;

                    return [
                        'goods_receipt_item_id' =>  (int) $row->goods_receipt_item_id,

                        'grn_number' => $row->grn_number,
                        'po_number' => $row->po_number,

                        'company_id' => (int) $row->company_id,
                        'item_id' => (int) $row->item_id,

                        'sku' => $row->sku,
                        'unit' => $row->unit,

                        'qty_received' =>  (string) $row->qty_received,

                        'qty_damaged' =>  (string) $row->qty_damaged,

                        'usable_units' => $usable,
                        'registered_units' => $registeredCount,
                        'remaining_units' =>  $usable - $registeredCount,

                        'is_asset_eligible' => true,
                    ];
                }
            )
        );

        return $page;
    }
}
