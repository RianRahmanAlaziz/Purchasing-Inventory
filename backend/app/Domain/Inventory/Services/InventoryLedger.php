<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use Illuminate\Support\Facades\DB;

final class InventoryLedger
{
    public function balance(Item $item, ?int $companyId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new StockConflictException('Pembacaan saldo terkunci wajib berada dalam transaksi.');
        }
        // Serialize all locations of an item in the same order as existing stock workflows.
        $locked = Item::query()->lockForUpdate()->findOrFail($item->id);
        if (! DB::table('inventory_balances')->where('item_id', $item->id)->exists()) {
            DB::table('inventory_balances')->insert([
                'item_id' => $item->id, 'company_id' => null, 'location_key' => 0,
                'quantity' => $locked->getRawOriginal('stock_quantity'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return StockDecimal::parse((string) (DB::table('inventory_balances')->where('item_id', $item->id)
            ->where('location_key', $companyId ?? 0)->lockForUpdate()->value('quantity') ?? '0'));
    }

    public function change(Item $item, ?int $companyId, int $difference, int $actorId, string $type, array $references = [], ?string $reason = null): StockMovement
    {
        if (DB::transactionLevel() < 1) {
            throw new StockConflictException('Mutasi wajib berada dalam transaksi dokumen.');
        }
        if ($companyId !== null && Company::query()->whereKey($companyId)->sharedLock()->first() === null) {
            throw new StockConflictException('PT lokasi tidak tersedia.');
        }
        $before = $this->balance($item, $companyId);
        $after = StockDecimal::add($before, $difference);
        if ($difference === 0 || $after < 0) {
            throw new StockConflictException('Stok PT tidak cukup atau jumlah mutasi nol.');
        }
        if ($item->item_type === 'office_asset' && $difference % 1000 !== 0) {
            throw new StockConflictException('Aset kantor harus menggunakan jumlah unit utuh.');
        }
        DB::table('inventory_balances')->updateOrInsert(
            ['item_id' => $item->id, 'location_key' => $companyId ?? 0],
            ['company_id' => $companyId, 'quantity' => StockDecimal::format($after), 'updated_at' => now(), 'created_at' => now()],
        );
        // Compatibility projection only; never increment the old column independently.
        $total = DB::table('inventory_balances')->where('item_id', $item->id)->sum('quantity');
        try {
            StockDecimal::parse((string) $total);
        } catch (\InvalidArgumentException) {
            throw new StockConflictException('Total stok melampaui kapasitas.');
        }
        DB::table('items')->where('id', $item->id)->update(['stock_quantity' => $total, 'updated_at' => now()]);

        return StockMovement::query()->create(array_merge($references, [
            'item_id' => $item->id, 'company_id' => $companyId, 'created_by' => $actorId,
            'movement_type' => $type, 'quantity_change' => StockDecimal::format($difference),
            'balance_before' => StockDecimal::format($before), 'balance_after' => StockDecimal::format($after),
            'reason' => $reason, 'created_at' => now(),
        ]));
    }
}
