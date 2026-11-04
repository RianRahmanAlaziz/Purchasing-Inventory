<?php

declare(strict_types=1);

namespace App\Domain\Item\Actions;

use App\Domain\Item\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteItem
{
    public function execute(Item $item): bool
    {
        try {
            return DB::transaction(function () use ($item): bool {
                $locked = Item::query()->lockForUpdate()
                    ->findOrFail($item->getKey());

                // Barang dengan saldo tidak nol tidak boleh dihapus.
                if ((string) $locked->stock_quantity !== '0.000') {
                    return false;
                }

                // Periksa seluruh jejak transaksi, termasuk PO soft-deleted.
                foreach ([
                    'purchase_order_items',
                    'distribution_items',
                    'stock_adjustments',
                    'stock_movements',
                ] as $table) {
                    if (DB::table($table)->where('item_id', $locked->getKey())->exists()) {
                        return false;
                    }
                }

                return (bool) $locked->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            // Perlindungan FK MySQL untuk referensi yang muncul bersamaan.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                return false;
            }

            throw $exception;
        }
    }
}
