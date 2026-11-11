<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\TaxConflictException;
use App\Domain\Tax\Models\TaxType;
use Illuminate\Support\Facades\DB;

final class SaveOfficePpn
{
    public const EFFECTIVE_FROM = '2025-01-01';

    public function __construct(private readonly SaveTaxRate $saveTaxRate) {}

    public function execute(bool $active = false): bool
    {
        return DB::transaction(function () use ($active): bool {
            $type = TaxType::query()->lockForUpdate()->firstOrCreate(
                ['code' => 'PPN_NON_MEWAH'],
                [
                    'name' => 'PPN Nonmewah',
                    'direction' => 'addition',
                    'description' => 'Untuk ATK dan perlengkapan kantor nonmewah yang dikenai PPN. Tarif efektif 11% dihitung dari tarif 12% dengan DPP 11/12 sesuai Pasal 3 PMK 131 Tahun 2024. https://jdih.kemenkeu.go.id/dok/pmk-131-tahun-2024',
                    'is_active' => $active,
                ],
            );
            if ($type->direction !== 'addition') {
                throw new TaxConflictException('Kode PPN_NON_MEWAH sudah digunakan untuk pajak potongan.');
            }
            if ($type->rates()->exists()) {
                return false;
            }

            $this->saveTaxRate->create([
                'tax_type_id' => $type->id,
                'label' => 'PPN Nonmewah',
                'rate_percent' => '12.0000',
                'taxable_base_factor' => '0.91666667',
                'effective_from' => self::EFFECTIVE_FROM,
                'is_active' => $active && $type->is_active,
            ]);

            return true;
        }, attempts: 3);
    }
}
