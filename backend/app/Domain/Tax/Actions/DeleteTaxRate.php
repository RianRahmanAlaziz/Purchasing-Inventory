<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\TaxConflictException;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteTaxRate
{
    public function execute(TaxRate $rate): void
    {
        try {
            DB::transaction(function () use ($rate): void {
                TaxType::query()->lockForUpdate()->findOrFail($rate->tax_type_id);
                $locked = TaxRate::query()->lockForUpdate()->findOrFail($rate->getKey());

                if (DB::table('purchase_order_taxes')
                    ->where('tax_rate_id', $locked->getKey())->exists()) {
                    throw new TaxConflictException(
                        'Tarif sudah digunakan PO. Nonaktifkan tarif agar riwayat tetap aman.'
                    );
                }

                $locked->delete();
            }, attempts: 3);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new TaxConflictException('Tarif masih direferensikan.', previous: $e);
            }
            throw $e;
        }
    }
}
