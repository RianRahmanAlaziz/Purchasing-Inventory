<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteSupplier
{
    public function execute(Supplier $supplier): bool
    {
        try {
            return DB::transaction(function () use ($supplier): bool {
                $locked = Supplier::query()
                    ->lockForUpdate()
                    ->findOrFail($supplier->getKey());

                // Termasuk PO yang telah di-soft-delete.
                if (DB::table('purchase_orders')
                    ->where('supplier_id', $locked->getKey())
                    ->exists()) {
                    return false;
                }

                return (bool) $locked->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            // 1451: foreign key MySQL melarang parent dihapus.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                return false;
            }

            throw $exception;
        }
    }
}
