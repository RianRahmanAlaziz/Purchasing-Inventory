<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\TaxConflictException;
use App\Domain\Tax\Models\TaxType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveTaxType
{
    public function create(array $data): TaxType
    {
        $data['is_active'] ??= true;

        try {
            return DB::transaction(
                static fn (): TaxType => TaxType::query()->create($data),
                attempts: 3,
            );
        } catch (QueryException $e) {
            $this->throwDuplicate($e);
            throw $e;
        }
    }

    public function update(TaxType $taxType, array $data): TaxType
    {
        try {
            return DB::transaction(function () use ($taxType, $data): TaxType {
                $locked = TaxType::query()->lockForUpdate()->findOrFail($taxType->getKey());

                // Mengubah arah jenis yang sudah mempunyai tarif akan mengubah
                // interpretasi tarif di PO masa depan; gunakan jenis baru.
                if (isset($data['direction'])
                    && $data['direction'] !== $locked->direction
                    && $locked->rates()->exists()) {
                    throw new TaxConflictException(
                        'Arah pajak tidak dapat diganti karena jenis ini sudah memiliki tarif.'
                    );
                }

                $locked->update($data);

                return $locked->refresh();
            }, attempts: 3);
        } catch (QueryException $e) {
            $this->throwDuplicate($e);
            throw $e;
        }
    }

    private function throwDuplicate(QueryException $e): void
    {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw ValidationException::withMessages([
                'code' => ['Kode jenis pajak sudah digunakan.'],
            ]);
        }
    }
}
