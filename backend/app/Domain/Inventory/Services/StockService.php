<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

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
    ): StockAdjustment {
        $expected = StockDecimal::parse($expectedBalance);
        $target = StockDecimal::parse($targetBalance);
        $reason = trim($reason);
        $idempotencyKey = strtolower($idempotencyKey);

        if ($reason === '') {
            throw new StockConflictException('Alasan adjustment wajib diisi.');
        }

        $fingerprint = hash('sha256', json_encode(
            [$actorId, $itemId, $expected, $target, $reason],
            JSON_THROW_ON_ERROR,
        ));

        try {
            return DB::transaction(function () use (
                $itemId, $expected, $target, $reason, $actorId,
                $idempotencyKey, $fingerprint,
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

                $before = StockDecimal::parse((string) $item->stock_quantity);

                if ($before !== $expected) {
                    throw new StockConflictException(
                        'Saldo barang berubah. Muat ulang stok sebelum adjustment.'
                    );
                }

                if ($target === $before) {
                    throw new StockConflictException('Saldo baru sama dengan saldo saat ini.');
                }

                $difference = $target - $before;

                $adjustment = StockAdjustment::query()->create([
                    'item_id' => $itemId,
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
                );

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

            $net = $received - $damaged;
            if ($net === 0) {
                return null;
            }

            return $this->writeMovement(
                $item, $net, $actorId, 'grn_in',
                ['goods_receipt_item_id' => $grnItemId],
                'Konfirmasi GRN #'.$grn->grn_number,
                (string) $poItem->unit_price,
            );
        }, attempts: 3);
    }

    /**
     * HANYA dipanggil oleh transaksi pengiriman distribusi (Fase Distribusi).
     * Status sent, sent_at, dan sent_by harus diset pada transaksi yang sama.
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

            if ($distribution === null || $distribution->status !== 'sent'
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

            return $this->writeMovement(
                $item, -$qty, $actorId, 'distribution_out',
                ['distribution_item_id' => $distributionItemId],
                'Pengiriman distribusi #'.$distribution->distribution_number,
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
    ): StockMovement {
        $before = StockDecimal::parse((string) $item->stock_quantity);
        $after = StockDecimal::add($before, $difference);

        if ($difference === 0) {
            throw new StockConflictException('Mutasi dengan selisih nol tidak diperbolehkan.');
        }

        $updates = [
            'stock_quantity' => StockDecimal::format($after),
            'updated_at' => now(),
        ];

        if ($lastPurchasePrice !== null) {
            $updates['last_purchase_price'] = $lastPurchasePrice;
        }

        DB::table('items')->where('id', $item->id)->update($updates);

        return StockMovement::query()->create(array_merge([
            'item_id' => $item->id,
            'created_by' => $actorId,
            'movement_type' => $type,
            'quantity_change' => StockDecimal::format($difference),
            'balance_before' => StockDecimal::format($before),
            'balance_after' => StockDecimal::format($after),
            'reason' => $reason,
            'created_at' => now(),
        ], $sourceColumns));
    }
}
