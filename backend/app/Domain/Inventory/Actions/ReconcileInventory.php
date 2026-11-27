<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use Illuminate\Support\Facades\DB;

final class ReconcileInventory
{
    public function execute(array $data, int $actorId): StockMovement
    {
        return DB::transaction(function () use ($data, $actorId): StockMovement {
            $item = Item::query()->lockForUpdate()->findOrFail($data['item_id']);
            $quantity = StockDecimal::parse($data['quantity']);
            if ($quantity <= 0 || $item->item_type === 'office_asset') {
                throw new StockConflictException('Alokasi membutuhkan jumlah positif. Alokasikan stok lama sebelum mengklasifikasikannya sebagai aset.');
            }
            $key = 'reconcile:'.strtolower($data['idempotency_key']);
            $prior = StockMovement::query()->where('operation_key', $key.':in')->first();
            if ($prior !== null) {
                if ((int) $prior->item_id !== (int) $item->id || (int) $prior->company_id !== (int) $data['company_id']
                    || StockDecimal::parse($prior->quantity_change) !== $quantity || $prior->reason !== $data['reason']
                    || (int) $prior->created_by !== $actorId) {
                    throw new StockConflictException('Kunci rekonsiliasi sudah digunakan untuk transaksi berbeda.');
                }

                return $prior;
            }
            $ledger = app(InventoryLedger::class);
            $ledger->change($item, null, -$quantity, $actorId, 'allocation_out', [
                'operation_key' => $key.':out', 'to_company_id' => $data['company_id'],
            ], $data['reason']);

            return $ledger->change($item, (int) $data['company_id'], $quantity, $actorId, 'allocation_in', [
                'operation_key' => $key.':in', 'to_company_id' => $data['company_id'],
            ], $data['reason']);
        }, attempts: 3);
    }
}
