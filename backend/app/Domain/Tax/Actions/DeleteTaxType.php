<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\TaxConflictException;
use App\Domain\Tax\Models\TaxType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteTaxType
{
    public function execute(TaxType $taxType): void
    {
        try {
            DB::transaction(function () use ($taxType): void {
                $locked = TaxType::query()->lockForUpdate()->findOrFail($taxType->getKey());

                if ($locked->rates()->exists()) {
                    throw new TaxConflictException(
                        'Jenis pajak memiliki tarif. Nonaktifkan jenis pajak agar riwayat tetap aman.'
                    );
                }

                $locked->delete();
            }, attempts: 3);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new TaxConflictException('Jenis pajak masih digunakan.', previous: $e);
            }
            throw $e;
        }
    }
}
