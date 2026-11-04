<?php

declare(strict_types=1);

namespace App\Domain\Receiving\Actions;

use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Receiving\Exceptions\GoodsReceiptConflict;
use App\Domain\Receiving\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** GRN baru maupun revisi Pending tidak pernah memanggil StockService. */
final class SaveGoodsReceipt
{
    /** @param array<string,mixed> $data */
    public function create(array $data, int $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $actorId): GoodsReceipt {
            $po = PurchaseOrder::query()->lockForUpdate()
                ->findOrFail($data['purchase_order_id']);
            $this->requireReceivable($po);
            $this->validateLines($po, $data['items']);

            $grn = GoodsReceipt::query()->create([
                'grn_number' => 'GRN-'.(string) Str::ulid(),
                'purchase_order_id' => $po->id,
                'created_by' => $actorId,
                'received_date' => $data['received_date'],
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
            ]);
            $grn->items()->createMany($this->formatLines($data['items']));

            return $this->loaded($grn);
        }, attempts: 3);
    }

    /** @param array<string,mixed> $data */
    public function update(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data): GoodsReceipt {
            // Konsisten dengan urutan lock PO -> Order -> GRN pada konfirmasi.
            $po = PurchaseOrder::query()->lockForUpdate()
                ->findOrFail($receipt->purchase_order_id);
            $this->requireReceivable($po);
            $grn = GoodsReceipt::query()->lockForUpdate()
                ->findOrFail($receipt->getKey());

            if ($grn->status !== 'pending' || $grn->confirmed_at !== null) {
                throw new GoodsReceiptConflict('Hanya GRN Pending yang boleh diubah.');
            }

            if (isset($data['items'])) {
                $this->validateLines($po, $data['items']);
                $grn->items()->delete();
                $grn->items()->createMany($this->formatLines($data['items']));
            }

            $grn->update(array_intersect_key($data, array_flip([
                'received_date', 'notes',
            ])));

            return $this->loaded($grn);
        }, attempts: 3);
    }

    public function requireReceivable(PurchaseOrder $po): void
    {
        if (! in_array($po->status, ['sent', 'received_by_supplier'], true)) {
            throw new GoodsReceiptConflict('GRN hanya boleh dibuat untuk PO Sent atau Received by Supplier.');
        }

        $order = Order::query()->where('purchase_order_id', $po->id)->first();
        if ($order === null || $order->status === 'cancelled') {
            throw new GoodsReceiptConflict('Order aktif dari PO tidak ditemukan.');
        }
    }

    /** @param array<int,array<string,mixed>> $lines */
    private function validateLines(PurchaseOrder $po, array $lines): void
    {
        $ids = array_map(static fn (array $line): int => (int) $line['purchase_order_item_id'], $lines);
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw new GoodsReceiptConflict('Item GRN harus unik dan minimal satu.');
        }
        $found = DB::table('purchase_order_items')
            ->where('purchase_order_id', $po->id)
            ->whereIn('id', $ids)->count();
        if ($found !== count($ids)) {
            throw new GoodsReceiptConflict('Terdapat item GRN yang bukan milik PO ini.');
        }
    }

    /** @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function formatLines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'purchase_order_item_id' => $line['purchase_order_item_id'],
            'qty_received' => $line['qty_received'],
            'qty_damaged' => $line['qty_damaged'] ?? '0.000',
            'notes' => $line['notes'] ?? null,
        ], $lines);
    }

    private function loaded(GoodsReceipt $receipt): GoodsReceipt
    {
        return $receipt->refresh()->load([
            'purchaseOrder', 'items.purchaseOrderItem', 'items.movement',
        ]);
    }
}
