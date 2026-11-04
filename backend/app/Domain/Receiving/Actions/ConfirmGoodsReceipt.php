<?php

declare(strict_types=1);

namespace App\Domain\Receiving\Actions;

use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Services\OrderStatusSync;
use App\Domain\Receiving\Exceptions\GoodsReceiptConflict;
use App\Domain\Receiving\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;

/** Konfirmasi GRN, mutasi stok dan sinkronisasi Order dalam SATU transaksi. */
final class ConfirmGoodsReceipt
{
    public function __construct(
        private readonly StockService $stock,
        private readonly OrderStatusSync $orderSync,
    ) {}

    public function execute(GoodsReceipt $receipt, int $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actorId): GoodsReceipt {
            // Semua konfirmasi GRN pada PO yang sama diserialisasikan melalui lock PO.
            // OrderStatusSync dan workflow supplier juga menggunakan PO -> Order.
            $po = PurchaseOrder::query()->lockForUpdate()
                ->findOrFail($receipt->purchase_order_id);
            if (! in_array($po->status, ['sent', 'received_by_supplier'], true)) {
                throw new GoodsReceiptConflict('PO harus Sent atau Received by Supplier.');
            }

            $order = Order::query()->where('purchase_order_id', $po->id)
                ->lockForUpdate()->first();
            if ($order === null || $order->status === 'cancelled') {
                throw new GoodsReceiptConflict('Order aktif tidak tersedia.');
            }

            $grn = GoodsReceipt::query()->lockForUpdate()
                ->findOrFail($receipt->getKey());
            if ((int) $grn->purchase_order_id !== (int) $po->id) {
                throw new GoodsReceiptConflict('PO pada GRN berubah.');
            }

            // Replay yang aman: status, stok, audit dan Order tidak diproses ulang.
            if ($grn->confirmed_at !== null) {
                if (! in_array($grn->status, [
                    'fully_received', 'partially_received', 'discrepancy',
                ], true)) {
                    throw new GoodsReceiptConflict('Status GRN yang sudah dikonfirmasi tidak valid.');
                }

                return $this->loaded($grn);
            }
            if ($grn->status !== 'pending') {
                throw new GoodsReceiptConflict('Hanya GRN Pending yang dapat dikonfirmasi.');
            }

            $lines = $grn->items()->orderBy('purchase_order_item_id')->get();
            if ($lines->isEmpty()) {
                throw new GoodsReceiptConflict('GRN harus memiliki minimal satu item.');
            }

            $poLines = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)
                ->orderBy('id')->get(['id', 'quantity']);

            // Hanya GRN confirmed sebelumnya; GRN Pending tidak dihitung sebagai stok.
            $previous = DB::table('goods_receipt_items as gri')
                ->join('goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
                ->where('gr.purchase_order_id', $po->id)
                ->where('gr.id', '!=', $grn->id)
                ->whereNotNull('gr.confirmed_at')
                ->whereIn('gr.status', ['fully_received', 'partially_received', 'discrepancy'])
                ->groupBy('gri.purchase_order_item_id')
                ->select('gri.purchase_order_item_id')
                ->selectRaw('COALESCE(SUM(gri.qty_received - gri.qty_damaged), 0) AS usable')
                ->get()->keyBy('purchase_order_item_id');

            $current = [];
            $hasDamage = false;
            foreach ($lines as $line) {
                $received = StockDecimal::parse((string) $line->qty_received);
                $damaged = StockDecimal::parse((string) $line->qty_damaged);
                if ($received <= 0 || $damaged > $received) {
                    throw new GoodsReceiptConflict('Jumlah diterima/rusak tidak valid.');
                }
                if (isset($current[(int) $line->purchase_order_item_id])) {
                    throw new GoodsReceiptConflict('Satu item PO tidak boleh diulang pada GRN yang sama.');
                }
                $current[(int) $line->purchase_order_item_id] = $received - $damaged;
                $hasDamage = $hasDamage || $damaged > 0;
            }

            $fullyReceived = true;
            foreach ($poLines as $poLine) {
                $ordered = StockDecimal::parse((string) $poLine->quantity);
                $previousUsable = StockDecimal::parse(
                    (string) ($previous->get($poLine->id)->usable ?? '0')
                );
                $newUsable = $current[(int) $poLine->id] ?? 0;
                $total = $previousUsable + $newUsable;

                if ($ordered <= 0 || $total > $ordered) {
                    throw new GoodsReceiptConflict(
                        'Barang layak pakai melebihi jumlah PO. Periksa penerimaan sebelumnya.'
                    );
                }
                $fullyReceived = $fullyReceived && $total === $ordered;
                unset($current[(int) $poLine->id]);
            }
            if ($current !== [] || $poLines->isEmpty()) {
                throw new GoodsReceiptConflict('Item penerimaan tidak sesuai dengan item PO.');
            }

            // Discrepancy menang jika ada barang rusak pada dokumen ini.
            // Status Order tetap berdasarkan akumulasi BARANG LAYAK PAKAI.
            $status = $hasDamage ? 'discrepancy'
                : ($fullyReceived ? 'fully_received' : 'partially_received');
            $grn->update([
                'status' => $status,
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ]);

            foreach ($lines as $line) {
                // Net 0 (semua rusak) sengaja tidak membuat stock movement.
                $this->stock->receiveConfirmedGrnItem((int) $line->id, $actorId);
            }

            $this->orderSync->afterConfirmedGrn((int) $po->id);

            return $this->loaded($grn);
        }, attempts: 3);
    }

    private function loaded(GoodsReceipt $receipt): GoodsReceipt
    {
        return $receipt->refresh()->load([
            'purchaseOrder', 'items.purchaseOrderItem', 'items.movement',
        ]);
    }
}
