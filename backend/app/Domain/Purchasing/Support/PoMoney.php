<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Nilai PO dihitung dengan operasi DECIMAL MySQL; agregasi di PHP memakai integer sen. */
final class PoMoney
{
    private const MAX_CENTS = 999999999999999999; // DECIMAL(18,2)

    public static function cents(string $amount): int
    {
        $amount = trim($amount);
        if (! preg_match('/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?$/D', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => ['Nilai uang harus DECIMAL(18,2) positif.']]);
        }

        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw ValidationException::withMessages(['amount' => ['Nilai uang melebihi kapasitas.']]);
        }

        return $cents;
    }

    public static function format(int $cents): string
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw ValidationException::withMessages(['amount' => ['Total PO di luar batas DECIMAL(18,2).']]);
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function add(int $first, int $second): int
    {
        if ($first < 0 || $second < 0 || $first > self::MAX_CENTS - $second) {
            throw ValidationException::withMessages(['amount' => ['Total PO melebihi kapasitas.']]);
        }

        return $first + $second;
    }

    public static function subtract(int $first, int $second): int
    {
        if ($first < $second) {
            throw ValidationException::withMessages(['tax_rate_ids' => ['Pajak pemotong tidak boleh membuat total PO negatif.']]);
        }

        return $first - $second;
    }

    public static function lineSubtotal(string $quantity, string $unitPrice): string
    {
        $result = DB::selectOne(
            'SELECT CAST(ROUND(CAST(? AS DECIMAL(18,3)) * CAST(? AS DECIMAL(18,2)), 2) AS CHAR) AS amount',
            [$quantity, $unitPrice]
        );

        return self::format(self::cents((string) $result->amount));
    }

    /** @return array{base:string,tax:string} */
    public static function tax(string $subtotal, string $factor, string $ratePercent): array
    {
        $base = (string) DB::selectOne(
            'SELECT CAST(ROUND(CAST(? AS DECIMAL(18,2)) * CAST(? AS DECIMAL(12,8)), 2) AS CHAR) AS amount',
            [$subtotal, $factor]
        )->amount;

        $base = self::format(self::cents($base));
        $tax = (string) DB::selectOne(
            'SELECT CAST(ROUND(CAST(? AS DECIMAL(18,2)) * CAST(? AS DECIMAL(9,4)) / 100, 2) AS CHAR) AS amount',
            [$base, $ratePercent]
        )->amount;

        return ['base' => $base, 'tax' => self::format(self::cents($tax))];
    }
}
