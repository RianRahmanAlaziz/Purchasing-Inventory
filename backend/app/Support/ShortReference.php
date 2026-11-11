<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

final class ShortReference
{
    public static function assign(Model $record, string $column, string $prefix): void
    {
        $number = $prefix.'-'.str_pad((string) $record->getKey(), 6, '0', STR_PAD_LEFT);
        $maxAttempts = 10;

        for ($suffix = 0; $suffix < $maxAttempts; $suffix++) {
            $candidate = $suffix === 0 ? $number : $number.'-'.$suffix;

            try {
                // A savepoint lets us retry if an existing code already uses this number.
                $record->getConnection()->transaction(fn () => $record->update([$column => $candidate]));

                return;
            } catch (UniqueConstraintViolationException $exception) {
                if ($suffix === $maxAttempts - 1) {
                    throw $exception;
                }
            }
        }
    }
}
