<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\Enums\OrderStatus;
use App\Domain\Purchasing\Exceptions\OrderConflict;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Panggil hanya dari transaksi konfirmasi GRN, sesudah StockService selesai.
 * Urutan lock antar-workflow: purchase_orders -> orders -> GRN/item/stock.
 * Tidak diekspos sebagai endpoint untuk mengubah status Order sesuka hati.
 */
final class OrderStatusSync
{
    public function __construct(private readonly OrderReceiptProgress $progress) {}

    public function afterConfirmedGrn(int $purchaseOrderId): Order
    {
        return DB::transaction(function () use ($purchaseOrderId): Order {
            $po = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrderId);

            if (! in_array($po->status, ['sent', 'received_by_supplier'], true)) {
                throw new OrderConflict('Penerimaan hanya boleh untuk PO Sent atau Received by Supplier.');
            }

            $order = Order::query()
                ->where('purchase_order_id', $po->getKey())
                ->lockForUpdate()
                ->first();

            if ($order === null || $order->status === OrderStatus::Cancelled->value) {
                throw new OrderConflict('Order aktif untuk PO tersebut tidak ditemukan.');
            }

            $progress = $this->progress->calculate($po);

            if ($progress['confirmed_grn_count'] === 0) {
                throw new OrderConflict('Belum ada GRN yang dikonfirmasi.');
            }

            // Jangan tandai Order received/completed jika saldo masuk GRN belum diaudit.
            // Item seluruhnya rusak (net 0) memang tidak menghasilkan stock movement.
            $missingMovement = DB::table('goods_receipt_items as gri')
                ->join('goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
                ->leftJoin('stock_movements as sm', 'sm.goods_receipt_item_id', '=', 'gri.id')
                ->where('gr.purchase_order_id', $purchaseOrderId)
                ->whereNotNull('gr.confirmed_at')
                ->whereIn('gr.status', ['fully_received', 'partially_received', 'discrepancy'])
                ->whereColumn('gri.qty_received', '>', 'gri.qty_damaged')
                ->whereNull('sm.id')
                ->exists();

            if ($missingMovement) {
                throw new OrderConflict('Ada penerimaan GRN tanpa audit mutasi stok.');
            }

            $target = match (true) {
                $progress['is_fully_received'] => OrderStatus::Completed,
                $progress['has_usable_receipt'] => OrderStatus::PartiallyReceived,
                $po->status === 'received_by_supplier' => OrderStatus::Confirmed,
                default => OrderStatus::Pending,
            };

            // Jangan pernah menurunkan status yang sudah tercapai.
            $rank = [
                OrderStatus::Pending->value => 0,
                OrderStatus::Confirmed->value => 1,
                OrderStatus::PartiallyReceived->value => 2,
                OrderStatus::Completed->value => 3,
            ];

            if (! array_key_exists($order->status, $rank)) {
                throw new OrderConflict('Status Order tidak valid.');
            }

            if ($rank[$target->value] < $rank[$order->status]) {
                throw new OrderConflict('Progres penerimaan tidak boleh menurunkan status Order.');
            }

            if ($target->value !== $order->status) {
                $updates = ['status' => $target->value];
                if ($target === OrderStatus::Completed) {
                    $updates['completed_at'] = $order->completed_at ?? now();
                }
                $order->update($updates);
            }

            return $order->refresh();
        }, attempts: 3);
    }
}
