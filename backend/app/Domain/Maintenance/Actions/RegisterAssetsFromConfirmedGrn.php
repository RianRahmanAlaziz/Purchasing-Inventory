<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use App\Domain\Maintenance\Exceptions\AssetRegistrationConflict;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use App\Domain\Receiving\Models\GoodsReceipt;
use App\Domain\Receiving\Models\GoodsReceiptItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class RegisterAssetsFromConfirmedGrn
{
    /**
     * @param array<string,mixed> $payload
     * @return array{replayed: bool, data: array<string,mixed>}
     */
    public function execute(array $payload, User $actor): array
    {
        $this->authorize($actor);

        $sourceId = (int) $payload['goods_receipt_item_id'];
        $key = strtolower((string) $payload['idempotency_key']);

        $units = array_map(
            static fn(array $unit): array => [
                'asset_name' => $unit['asset_name'],
                'asset_type' => $unit['asset_type'],
                'serial_number' => $unit['serial_number'] ?? null,
            ],
            $payload['units']
        );

        $fingerprint = hash('sha256', json_encode([
            'actor_id' => (int) $actor->id,
            'goods_receipt_item_id' => $sourceId,
            'units' => $units,
        ], JSON_THROW_ON_ERROR));

        // Pembacaan awal hanya menentukan ID untuk urutan lock.
        // Seluruh relasi harus diverifikasi lagi dalam transaksi.
        $source = DB::table('goods_receipt_items as gri')
            ->join(
                'goods_receipts as gr',
                'gr.id',
                '=',
                'gri.goods_receipt_id'
            )
            ->where('gri.id', $sourceId)
            ->first([
                'gri.goods_receipt_id',
                'gri.purchase_order_item_id',
                'gr.purchase_order_id',
            ]);

        if ($source === null) {
            abort(404);
        }

        try {
            return DB::transaction(function () use (
                $source,
                $sourceId,
                $key,
                $units,
                $fingerprint,
                $actor
            ): array {

                // Konsisten dengan ConfirmGoodsReceipt:
                // PO -> Order -> GRN -> GRN Item.
                $po = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($source->purchase_order_id);

                $order = Order::query()
                    ->where('purchase_order_id', $po->id)
                    ->lockForUpdate()
                    ->first();

                $grn = GoodsReceipt::query()
                    ->lockForUpdate()
                    ->findOrFail($source->goods_receipt_id);

                $line = GoodsReceiptItem::query()
                    ->lockForUpdate()
                    ->findOrFail($sourceId);

                if (
                    (int) $line->goods_receipt_id !== (int) $grn->id
                    || (int) $grn->purchase_order_id !== (int) $po->id
                    || (int) $line->purchase_order_item_id
                    !== (int) $source->purchase_order_item_id
                ) {
                    throw new AssetRegistrationConflict(
                        'Relasi asal GRN tidak konsisten.'
                    );
                }

                $this->authorize($actor);

                // Locking read agar retry melihat batch yang
                // telah di-commit oleh transaksi sebelumnya.
                $prior = DB::table('maintenance_asset_registrations')
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($prior !== null) {
                    if (
                        (int) $prior->registered_by !== (int) $actor->id
                        || (int) $prior->goods_receipt_item_id !== $sourceId
                        || (int) $prior->company_id !== (int) $po->company_id
                        || ! hash_equals(
                            (string) $prior->request_fingerprint,
                            $fingerprint
                        )
                        || $prior->response_payload === null
                    ) {
                        throw new AssetRegistrationConflict(
                            'Idempotency key sudah dipakai untuk permintaan berbeda.'
                        );
                    }

                    return [
                        'replayed' => true,
                        'data' => json_decode(
                            (string) $prior->response_payload,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        ),
                    ];
                }

                // Hanya GRN confirmed dengan Order aktif.
                if (
                    ! in_array(
                        $po->status,
                        ['sent', 'received_by_supplier'],
                        true
                    )
                    || $order === null
                    || ! in_array(
                        $order->status,
                        ['partially_received', 'completed'],
                        true
                    )
                    || $grn->confirmed_at === null
                    || ! in_array(
                        $grn->status,
                        [
                            'fully_received',
                            'partially_received',
                            'discrepancy',
                        ],
                        true
                    )
                ) {
                    throw new AssetRegistrationConflict(
                        'GRN belum confirmed atau status PO/Order tidak sesuai.'
                    );
                }

                $poItem = PurchaseOrderItem::query()
                    ->lockForUpdate()
                    ->findOrFail($line->purchase_order_item_id);

                if ((int) $poItem->purchase_order_id !== (int) $po->id) {
                    throw new AssetRegistrationConflict(
                        'Baris GRN bukan dari PO terkait.'
                    );
                }

                $item = Item::query()
                    ->lockForUpdate()
                    ->findOrFail($poItem->item_id);

                if (
                    ! $item->is_active
                    || ! $item->is_asset_trackable
                ) {
                    throw new AssetRegistrationConflict(
                        'SKU belum diizinkan untuk registrasi aset.'
                    );
                }

                // PT pemilik berasal dari PO, bukan request user.
                $company = $po->company_id === null
                    ? null
                    : Company::query()
                    ->lockForUpdate()
                    ->find($po->company_id);

                if ($company === null) {
                    throw new AssetRegistrationConflict(
                        'PT pemilik pada PO belum terverifikasi.'
                    );
                }

                // Penerimaan positif harus memiliki stock movement
                // yang dibuat oleh proses konfirmasi GRN existing.
                if (! DB::table('stock_movements')
                    ->where('goods_receipt_item_id', $line->id)
                    ->where('movement_type', 'grn_in')
                    ->exists()) {
                    throw new AssetRegistrationConflict(
                        'Audit stok GRN confirmed belum tersedia.'
                    );
                }

                // Hitung menggunakan integer milliunit.
                // Jangan pernah membulatkan kuantitas pecahan.
                $received = StockDecimal::parse(
                    (string) $line->qty_received
                );

                $damaged = StockDecimal::parse(
                    (string) $line->qty_damaged
                );

                $usable = $received - $damaged;

                if (
                    $received <= 0
                    || $damaged > $received
                    || $usable <= 0
                    || $usable % 1000 !== 0
                ) {
                    throw new AssetRegistrationConflict(
                        'Unit baik GRN harus positif dan bilangan bulat.'
                    );
                }

                $usableUnits = intdiv($usable, 1000);

                // Hitung seluruh aset dari baris GRN, termasuk
                // yang sudah berstatus nonaktif.
                $registered = DB::table('maintenance_assets')
                    ->where('source_goods_receipt_item_id', $line->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get([
                        'id',
                        'receipt_unit_ordinal',
                    ]);

                $used = $registered->count();

                $lastOrdinal = (int) $registered->max(
                    'receipt_unit_ordinal'
                );

                $quantity = count($units);

                if (
                    $lastOrdinal + $quantity > $usableUnits
                    || $used + $quantity > $usableUnits
                ) {
                    throw new AssetRegistrationConflict(
                        'Kuota unit baik pada GRN tidak mencukupi.'
                    );
                }

                // Nomor urut per PT harus menggunakan row lock.
                $sequence = DB::table('maintenance_asset_sequences')
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $sequence === null
                    || ! is_string($sequence->prefix)
                    || preg_match(
                        '/\A[A-Z][A-Z0-9]{1,9}\z/D',
                        $sequence->prefix
                    ) !== 1
                ) {
                    throw new AssetRegistrationConflict(
                        'Prefix nomor aset PT belum dikonfigurasi.'
                    );
                }

                if (
                    (int) $sequence->last_sequence
                    > PHP_INT_MAX - $quantity
                ) {
                    throw new AssetRegistrationConflict(
                        'Nomor urut aset PT telah mencapai batas.'
                    );
                }

                $now = now();

                // Batch dan semua unit dibuat dalam satu transaksi.
                $batchId = DB::table(
                    'maintenance_asset_registrations'
                )->insertGetId([
                    'company_id' => $company->id,
                    'goods_receipt_item_id' => $line->id,
                    'registered_by' => $actor->id,
                    'idempotency_key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'unit_count' => $quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $newAssets = [];
                $nextSequence = (int) $sequence->last_sequence;

                foreach ($units as $position => $unit) {
                    ++$nextSequence;

                    $code = $sequence->prefix
                        . '-AST-'
                        . str_pad(
                            (string) $nextSequence,
                            6,
                            '0',
                            STR_PAD_LEFT
                        );

                    $assetId = DB::table(
                        'maintenance_assets'
                    )->insertGetId([
                        'maintenance_asset_registration_id' => $batchId,

                        'company_id' => $company->id,
                        'sequence_number' => $nextSequence,
                        'asset_code' => $code,

                        'asset_name' => $unit['asset_name'],
                        'asset_type' => $unit['asset_type'],
                        'serial_number' => $unit['serial_number'],

                        'item_id' => $item->id,
                        'source_goods_receipt_item_id' => $line->id,
                        'receipt_unit_ordinal' =>
                        $lastOrdinal + $position + 1,

                        'registration_source' => 'confirmed_grn',
                        'registered_by' => $actor->id,
                        'registered_at' => $now,
                        'is_active' => true,

                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $newAssets[] = [
                        'id' => $assetId,
                        'asset_code' => $code,
                        'asset_name' => $unit['asset_name'],
                    ];
                }

                DB::table('maintenance_asset_sequences')
                    ->where('company_id', $company->id)
                    ->update([
                        'last_sequence' => $nextSequence,
                        'updated_at' => $now,
                    ]);

                // Simpan snapshot response untuk retry yang stabil.
                $data = [
                    'registration_id' => $batchId,
                    'source' => 'confirmed_grn',
                    'goods_receipt_item_id' => (int) $line->id,
                    'company_id' => (int) $company->id,
                    'registered_count' => $quantity,
                    'registered_units' => $used + $quantity,
                    'remaining_units' =>
                    $usableUnits - $used - $quantity,
                    'assets' => $newAssets,
                ];

                DB::table('maintenance_asset_registrations')
                    ->where('id', $batchId)
                    ->update([
                        'response_payload' => json_encode(
                            $data,
                            JSON_THROW_ON_ERROR
                        ),
                        'updated_at' => $now,
                    ]);

                return [
                    'replayed' => false,
                    'data' => $data,
                ];
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new AssetRegistrationConflict(
                    'Konflik nomor aset/ordinal/idempotensi. Ulangi dengan data terbaru.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    private function authorize(User $actor): void
    {
        if (
            ! $actor->is_active
            || ! $actor->hasAnyRole(['super_admin', 'hr'], 'web')
            || ! $actor->can('maintenance.assets.register-from-receipt')
            || ! $actor->can('maintenance.assets.manage-all-companies')
        ) {
            throw new AuthorizationException(
                'Tidak memiliki izin registrasi aset dari GRN.'
            );
        }
    }
}
