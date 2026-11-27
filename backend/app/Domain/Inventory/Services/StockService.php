<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Asset\Actions\AssetWorkflow;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Seluruh mutasi saldo HARUS melalui layanan ini. Tidak membuka API generik
 * untuk GRN masuk / distribusi keluar: transisi dokumen menjadi prasyarat.
 */
final class StockService
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * Set saldo hasil stock opname; expectedBalance melindungi dari data usang.
     * Idempotency key berlaku global untuk semua adjustment.
     */
    public function adjustTo(
        int $itemId,
        string $expectedBalance,
        string $targetBalance,
        string $reason,
        int $actorId,
        string $idempotencyKey,
        ?int $companyId = null,
    ): StockAdjustment {
        $expected = StockDecimal::parse($expectedBalance);
        $target = StockDecimal::parse($targetBalance);
        $reason = trim($reason);
        $idempotencyKey = strtolower($idempotencyKey);

        if ($reason === '') {
            throw new StockConflictException('Alasan adjustment wajib diisi.');
        }

        $fingerprint = hash('sha256', json_encode(
            [$actorId, $itemId, $expected, $target, $reason, $companyId],
            JSON_THROW_ON_ERROR,
        ));

        try {
            return DB::transaction(function () use (
                $itemId, $expected, $target, $reason, $actorId,
                $idempotencyKey, $fingerprint, $companyId,
            ): StockAdjustment {
                // Cek replay di awal, tetapi tetap andalkan UNIQUE DB untuk race.
                $prior = StockAdjustment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($prior !== null) {
                    return $this->replayOrConflict($prior, $fingerprint);
                }

                $item = Item::query()->lockForUpdate()->findOrFail($itemId);

                // Setelah lock, cek sekali lagi karena request lain mungkin baru commit.
                $prior = StockAdjustment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($prior !== null) {
                    return $this->replayOrConflict($prior, $fingerprint);
                }

                if ($item->awaiting_first_receipt) {
                    throw new StockConflictException('Barang dari PO menunggu konfirmasi penerimaan pertama.');
                }

                $before = $this->ledger->balance($item, $companyId);

                if ($before !== $expected) {
                    throw new StockConflictException(
                        'Saldo barang berubah. Muat ulang stok sebelum adjustment.'
                    );
                }

                if ($target === $before) {
                    throw new StockConflictException('Saldo baru sama dengan saldo saat ini.');
                }

                $difference = $target - $before;
                if ($item->item_type === 'office_asset' && ($companyId === null || $target % 1000 !== 0 || $difference < 0)) {
                    throw new StockConflictException('Aset memerlukan PT dan unit utuh. Gunakan kondisi/retur aset untuk pengurangan agar identitas tetap terlacak.');
                }

                $adjustment = StockAdjustment::query()->create([
                    'item_id' => $itemId,
                    'company_id' => $companyId,
                    'adjusted_by' => $actorId,
                    'stock_before' => StockDecimal::format($before),
                    'stock_after' => StockDecimal::format($target),
                    'quantity_difference' => StockDecimal::format($difference),
                    'reason' => $reason,
                    'applied_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);

                $this->writeMovement(
                    $item,
                    $difference,
                    $actorId,
                    'adjustment',
                    ['stock_adjustment_id' => $adjustment->id],
                    $reason,
                    companyId: $companyId,
                );
                if ($item->item_type === 'office_asset') {
                    app(AssetWorkflow::class)->registerUnits($item, $companyId, intdiv($difference, 1000), $actorId, ['source_type' => 'stock_adjustment', 'source_id' => $adjustment->id]);
                }

                return $adjustment->load('movement');
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            // Unique key menang atas request paralel dengan idempotency key sama.
            $prior = StockAdjustment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($prior === null) {
                throw $exception;
            }

            return $this->replayOrConflict($prior, $fingerprint);
        }
    }

    /**
     * HANYA dipanggil oleh transaksi konfirmasi GRN (Fase GRN).
     * Status + confirmed_at harus sudah diubah pada transaksi yang sama.
     * Mengembalikan null bila qty diterima seluruhnya rusak (net = 0).
     */
    public function receiveConfirmedGrnItem(int $grnItemId, int $actorId): ?StockMovement
    {
        return DB::transaction(function () use ($grnItemId, $actorId): ?StockMovement {
            $source = DB::table('goods_receipt_items')->where('id', $grnItemId)->first();

            if ($source === null) {
                throw new StockConflictException('Item GRN tidak ditemukan.');
            }

            $grn = DB::table('goods_receipts')->where('id', $source->goods_receipt_id)
                ->lockForUpdate()->first();

            if ($grn === null || ! in_array($grn->status, [
                'fully_received', 'partially_received', 'discrepancy',
            ], true) || $grn->confirmed_at === null) {
                throw new StockConflictException('GRN belum dikonfirmasi. Stok tidak berubah.');
            }

            $source = DB::table('goods_receipt_items')->where('id', $grnItemId)
                ->lockForUpdate()->first();
            $poItem = DB::table('purchase_order_items')
                ->where('id', $source->purchase_order_item_id)->first();

            if ($poItem === null || (int) $poItem->purchase_order_id !== (int) $grn->purchase_order_id) {
                throw new StockConflictException('Item GRN tidak sesuai dengan PO.');
            }

            $item = Item::query()->lockForUpdate()->findOrFail($poItem->item_id);
            $prior = StockMovement::query()->where('goods_receipt_item_id', $grnItemId)->first();

            if ($prior !== null) {
                return $prior; // satu GRN item hanya satu mutasi masuk
            }

            $received = StockDecimal::parse((string) $source->qty_received);
            $damaged = StockDecimal::parse((string) $source->qty_damaged);

            if ($received <= 0 || $damaged > $received) {
                throw new StockConflictException('Kuantitas GRN tidak valid.');
            }

            if ($item->item_type === 'office_asset' && ($received % 1000 !== 0 || $damaged % 1000 !== 0)) {
                throw new StockConflictException('Jumlah aset diterima/rusak harus unit utuh.');
            }
            $net = $received - $damaged;
            if ($net === 0) {
                return null;
            }

            if ($grn->company_id === null) {
                throw new StockConflictException('Pilih PT tujuan penerimaan sebelum konfirmasi GRN.');
            }
            $movement = $this->writeMovement(
                $item, $net, $actorId, 'grn_in',
                ['goods_receipt_item_id' => $grnItemId],
                'Konfirmasi GRN #'.$grn->grn_number,
                (string) $poItem->unit_price,
                companyId: (int) $grn->company_id,
            );
            if ($item->item_type === 'office_asset') {
                app(AssetWorkflow::class)->registerUnits($item, (int) $grn->company_id, intdiv($net, 1000), $actorId, ['source_type' => 'goods_receipt_item', 'source_id' => $grnItemId]);
            }

            return $movement;
        }, attempts: 3);
    }

    /**
     * HANYA dipanggil oleh transaksi pengiriman distribusi (Fase Distribusi).
     * Status sent/completed, sent_at, dan sent_by harus diset pada transaksi yang sama.
     */
    public function issueSentDistributionItem(int $distributionItemId, int $actorId): StockMovement
    {
        return DB::transaction(function () use ($distributionItemId, $actorId): StockMovement {
            $source = DB::table('distribution_items')->where('id', $distributionItemId)->first();

            if ($source === null) {
                throw new StockConflictException('Item distribusi tidak ditemukan.');
            }

            $distribution = DB::table('distributions')
                ->where('id', $source->distribution_id)->lockForUpdate()->first();

            if ($distribution === null || ! in_array($distribution->status, ['sent', 'completed'], true)
                || $distribution->sent_at === null || (int) $distribution->sent_by !== $actorId) {
                throw new StockConflictException('Distribusi belum dikirim oleh aktor ini.');
            }

            $source = DB::table('distribution_items')->where('id', $distributionItemId)
                ->lockForUpdate()->first();
            $item = Item::query()->lockForUpdate()->findOrFail($source->item_id);
            $prior = StockMovement::query()
                ->where('distribution_item_id', $distributionItemId)->first();

            if ($prior !== null) {
                return $prior;
            }

            $qty = StockDecimal::parse((string) $source->quantity);

            if ($qty <= 0) {
                throw new StockConflictException('Kuantitas distribusi harus lebih dari nol.');
            }

            if ($distribution->company_id === null) {
                throw new StockConflictException('Pilih PT asal distribusi.');
            }
            if ($item->item_type === 'office_asset') {
                app(AssetWorkflow::class)->issueDistribution(
                    $item, (int) $distribution->company_id, json_decode($source->asset_ids ?? '[]', true),
                    (string) ($distribution->recipient_name_snapshot ?? ''), $actorId, $distributionItemId,
                );
            }

            return $this->writeMovement(
                $item, -$qty, $actorId, 'distribution_out',
                ['distribution_item_id' => $distributionItemId],
                'Pengiriman distribusi #'.$distribution->distribution_number,
                companyId: (int) $distribution->company_id,
            );
        }, attempts: 3);
    }

    private function replayOrConflict(StockAdjustment $prior, string $fingerprint): StockAdjustment
    {
        if ($prior->request_fingerprint === null || ! hash_equals($prior->request_fingerprint, $fingerprint)) {
            throw new StockConflictException('Idempotency-Key sudah dipakai untuk permintaan berbeda.');
        }

        return $prior->load('movement');
    }

    /** Pemanggil sudah memegang lock baris items di dalam DB::transaction(). */
    private function writeMovement(
        Item $item,
        int $difference,
        int $actorId,
        string $type,
        array $sourceColumns,
        ?string $reason = null,
        ?string $lastPurchasePrice = null,
        ?int $companyId = null,
    ): StockMovement {
        $movement = $this->ledger->change($item, $companyId, $difference, $actorId, $type, $sourceColumns, $reason);
        $updates = [];
        if ($lastPurchasePrice !== null) {
            $updates['last_purchase_price'] = $lastPurchasePrice;
        }
        if ($type === 'grn_in') {
            $updates['awaiting_first_receipt'] = false;
        }
        if ($updates !== []) {
            DB::table('items')->where('id', $item->id)->update($updates);
        }

        return $movement;
    }
}
