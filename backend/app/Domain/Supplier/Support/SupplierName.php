<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Support;

use Illuminate\Support\Str;

final class SupplierName
{
    public static function display(string $name): string
    {
        return Str::squish($name);
    }

    public static function normalized(string $name): string
    {
        return Str::lower(self::display($name));
    }
}
