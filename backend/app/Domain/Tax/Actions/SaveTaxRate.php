<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\TaxConflictException;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxType;
use Illuminate\Support\Facades\DB;

final class SaveTaxRate
{
    public function create(array $data): TaxRate
    {
        $data['is_active'] ??= true;
        $data['taxable_base_factor'] ??= '1.00000000';

        return DB::transaction(function () use ($data): TaxRate {
            // Kunci induk untuk menserialisasikan semua pembuatan/perubahan
            // tarif pada jenis pajak yang sama.
            $type = TaxType::query()->lockForUpdate()->findOrFail($data['tax_type_id']);
            $this->assertActiveTypeIfNeeded($type, (bool) $data['is_active']);
            $this->assertValidPeriod($data['effective_from'], $data['effective_until'] ?? null);
            $this->assertNoOverlap($type, $data);

            return TaxRate::query()->create($data)->load('taxType');
        }, attempts: 3);
    }

    public function update(TaxRate $rate, array $changes): TaxRate
    {
        return DB::transaction(function () use ($rate, $changes): TaxRate {
            // Relasi tax_type_id sengaja tidak dapat diganti setelah pembuatan.
            // Kunci induk dahulu; urutan lock yang tetap mengurangi potensi deadlock.
            $typeId = $rate->tax_type_id;
            $type = TaxType::query()->lockForUpdate()->findOrFail($typeId);
            $locked = TaxRate::query()->lockForUpdate()->findOrFail($rate->getKey());

            if ((int) $locked->tax_type_id !== (int) $typeId) {
                throw new TaxConflictException('Tarif berubah ketika diproses; coba lagi.');
            }

            $hasPO = DB::table('purchase_order_taxes')
                ->where('tax_rate_id', $locked->getKey())->exists();

            if ($hasPO) {
                // Referensi sudah digunakan dalam PO. Metadata angka dan
                // periode dibekukan; hanya status aktif boleh diubah.
                foreach ($changes as $field => $value) {
                    if ($field !== 'is_active' && (string) $locked->{$field} !== (string) $value) {
                        throw new TaxConflictException(
                            'Tarif sudah digunakan PO. Hanya status aktif yang boleh diubah.'
                        );
                    }
                }
            }

            $candidate = array_merge(
                $locked->only([
                    'tax_type_id', 'label', 'rate_percent', 'taxable_base_factor',
                    'effective_from', 'effective_until', 'is_active',
                ]),
                $changes,
            );
            // Eloquent mengembalikan Carbon untuk tanggal sehingga dinormalisasi.
            $from = is_string($candidate['effective_from'])
                ? substr($candidate['effective_from'], 0, 10)
                : $candidate['effective_from']->format('Y-m-d');
            $until = $candidate['effective_until'];
            if ($until !== null && ! is_string($until)) {
                $until = $until->format('Y-m-d');
            }
            $candidate['effective_from'] = $from;
            $candidate['effective_until'] = $until;

            $this->assertValidPeriod($from, $until);
            $this->assertActiveTypeIfNeeded($type, (bool) $candidate['is_active']);
            $this->assertNoOverlap($type, $candidate, (int) $locked->getKey());

            $locked->update($changes);

            return $locked->refresh()->load('taxType');
        }, attempts: 3);
    }

    private function assertActiveTypeIfNeeded(TaxType $type, bool $active): void
    {
        if ($active && ! $type->is_active) {
            throw new TaxConflictException(
                'Jenis pajak nonaktif. Aktifkan jenis terlebih dahulu atau simpan tarif sebagai nonaktif.'
            );
        }
    }

    private function assertValidPeriod(string $from, ?string $until): void
    {
        if ($until !== null && $until < $from) {
            throw new TaxConflictException('Tanggal akhir tidak boleh sebelum tanggal mulai tarif.');
        }
    }

    private function assertNoOverlap(TaxType $type, array $candidate, ?int $except = null): void
    {
        if (! $candidate['is_active']) {
            return;
        }

        $end = $candidate['effective_until'] ?? '9999-12-31';
        $query = $type->rates()
            ->where('is_active', true)
            ->where('effective_from', '<=', $end)
            ->where(function ($query) use ($candidate): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $candidate['effective_from']);
            });

        if ($except !== null) {
            $query->where('tax_rates.id', '!=', $except);
        }

        if ($query->exists()) {
            throw new TaxConflictException(
                'Ada tarif aktif dengan periode berlaku yang bertumpang tindih pada jenis pajak ini.'
            );
        }
    }
}
