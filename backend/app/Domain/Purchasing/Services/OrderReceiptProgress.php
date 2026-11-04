<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Purchasing\Exceptions\OrderConflict;
use App\Domain\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Proyeksi progres order dari GRN yang SUDAH dikonfirmasi.
 * Tidak menulis stok ataupun status dokumen.
 */
final class OrderReceiptProgress
{
    private const CONFIRMED_GRN_STATUSES = [
        'fully_received', 'partially_received', 'discrepancy',
    ];

    /** @return array<string, mixed> */
    public function calculate(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing('items');

        $quantities = DB::table('goods_receipt_items as gri')
            ->join('goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
            ->where('gr.purchase_order_id', $purchaseOrder->getKey())
            ->whereNotNull('gr.confirmed_at')
            ->whereIn('gr.status', self::CONFIRMED_GRN_STATUSES)
            ->groupBy('gri.purchase_order_item_id')
            ->select('gri.purchase_order_item_id')
            ->selectRaw('COALESCE(SUM(gri.qty_received), 0) as gross_received')
            ->selectRaw('COALESCE(SUM(gri.qty_damaged), 0) as damaged')
            ->selectRaw('COALESCE(SUM(gri.qty_received - gri.qty_damaged), 0) as usable_received')
            ->get()
            ->keyBy('purchase_order_item_id');

        $lines = [];
        $totalsByUnit = [];
        $hasUsableReceipt = false;
        $isFullyReceived = true;

        foreach ($purchaseOrder->items as $poItem) {
            $received = $quantities->get($poItem->getKey());
            $orderedMilli = StockDecimal::parse((string) $poItem->quantity);
            $grossMilli = StockDecimal::parse((string) ($received->gross_received ?? '0'));
            $damagedMilli = StockDecimal::parse((string) ($received->damaged ?? '0'));
            $usableMilli = StockDecimal::parse((string) ($received->usable_received ?? '0'));

            if ($orderedMilli <= 0 || $grossMilli < $damagedMilli
                || $usableMilli !== $grossMilli - $damagedMilli
                || $usableMilli > $orderedMilli) {
                throw new OrderConflict('Data kuantitas penerimaan GRN tidak konsisten dengan PO.');
            }

            // Jangan menjumlahkan satuan yang berbeda (mis. pcs + kg).
            $unit = strtolower(trim((string) $poItem->unit_snapshot));
            $totalsByUnit[$unit] ??= ['ordered' => 0, 'gross' => 0, 'damaged' => 0, 'usable' => 0];
            $totalsByUnit[$unit]['ordered'] += $orderedMilli;
            $totalsByUnit[$unit]['gross'] += $grossMilli;
            $totalsByUnit[$unit]['damaged'] += $damagedMilli;
            $totalsByUnit[$unit]['usable'] += $usableMilli;
            $hasUsableReceipt = $hasUsableReceipt || $usableMilli > 0;
            $isFullyReceived = $isFullyReceived && $usableMilli === $orderedMilli;

            $lines[] = [
                'purchase_order_item_id' => $poItem->id,
                'item_id' => $poItem->item_id,
                'sku' => $poItem->item_sku_snapshot,
                'name' => $poItem->item_name_snapshot,
                'unit' => $poItem->unit_snapshot,
                'ordered_quantity' => StockDecimal::format($orderedMilli),
                'received_quantity' => StockDecimal::format($grossMilli),
                'damaged_quantity' => StockDecimal::format($damagedMilli),
                'usable_received_quantity' => StockDecimal::format($usableMilli),
                'remaining_quantity' => StockDecimal::format($orderedMilli - $usableMilli),
            ];
        }

        if ($lines === []) {
            throw new OrderConflict('Order tidak memiliki item PO yang valid.');
        }

        $confirmedCount = DB::table('goods_receipts')
            ->where('purchase_order_id', $purchaseOrder->getKey())
            ->whereNotNull('confirmed_at')
            ->whereIn('status', self::CONFIRMED_GRN_STATUSES)
            ->count();

        $pendingCount = DB::table('goods_receipts')
            ->where('purchase_order_id', $purchaseOrder->getKey())
            ->where('status', 'pending')
            ->count();

        $byUnit = [];
        foreach ($totalsByUnit as $unit => $sums) {
            $byUnit[] = [
                'unit' => $unit,
                'ordered_quantity' => StockDecimal::format($sums['ordered']),
                'received_quantity' => StockDecimal::format($sums['gross']),
                'damaged_quantity' => StockDecimal::format($sums['damaged']),
                'usable_received_quantity' => StockDecimal::format($sums['usable']),
                'remaining_quantity' => StockDecimal::format($sums['ordered'] - $sums['usable']),
            ];
        }

        // Ringkasan flat tersedia hanya bila seluruh item memiliki satuan sama.
        $single = count($byUnit) === 1 ? $byUnit[0] : null;

        return [
            'ordered_quantity' => $single['ordered_quantity'] ?? null,
            'received_quantity' => $single['received_quantity'] ?? null,
            'damaged_quantity' => $single['damaged_quantity'] ?? null,
            'usable_received_quantity' => $single['usable_received_quantity'] ?? null,
            'remaining_quantity' => $single['remaining_quantity'] ?? null,
            'quantities_by_unit' => $byUnit,
            'confirmed_grn_count' => $confirmedCount,
            'pending_grn_count' => $pendingCount,
            'has_usable_receipt' => $hasUsableReceipt,
            'is_fully_received' => $isFullyReceived,
            'items' => $lines,
        ];
    }
}
