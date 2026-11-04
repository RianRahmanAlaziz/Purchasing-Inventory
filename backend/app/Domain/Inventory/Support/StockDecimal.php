<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Exceptions\StockConflictException;
use InvalidArgumentException;

/** Kalkulasi DECIMAL(18,3) menggunakan integer milliunit; tidak memakai float. */
final class StockDecimal
{
    public const MAX_MILLI = 999999999999999999;

    public static function parse(string $value, bool $allowNegative = false): int
    {
        if (! preg_match('/^(-?)(0|[1-9][0-9]{0,14})(?:\.([0-9]{1,3}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Kuantitas harus DECIMAL(18,3) yang valid.');
        }

        if ($matches[1] === '-' && ! $allowNegative) {
            throw new InvalidArgumentException('Kuantitas tidak boleh negatif.');
        }

        $milli = ((int) $matches[2] * 1000)
            + (int) str_pad($matches[3] ?? '', 3, '0');

        return $matches[1] === '-' ? -$milli : $milli;
    }

    public static function format(int $milli): string
    {
        if ($milli > self::MAX_MILLI || $milli < -self::MAX_MILLI) {
            throw new InvalidArgumentException('Kuantitas melewati batas DECIMAL(18,3).');
        }

        $absolute = abs($milli);

        return ($milli < 0 ? '-' : '')
            .intdiv($absolute, 1000)
            .'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function add(int $balance, int $delta): int
    {
        $next = $balance + $delta;

        if ($next < 0) {
            throw new StockConflictException('Stok tidak mencukupi.');
        }

        if ($next > self::MAX_MILLI) {
            throw new StockConflictException('Kuantitas melebihi kapasitas stok.');
        }

        return $next;
    }
}
