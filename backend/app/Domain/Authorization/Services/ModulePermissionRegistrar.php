<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class ModulePermissionRegistrar
{
    /**
     * Register permissions without granting them to roles.
     *
     * Example:
     * register(['maintenance.view', 'maintenance.work-orders.create'])
     *
     * @param array<int, string> $names
     */
    public function register(array $names): void
    {
        // Validasi seluruh nama sebelum menulis ke database.
        foreach ($names as $name) {
            if (
                ! is_string($name)
                || preg_match(
                    '/\A[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+\z/',
                    $name
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Nama permission tidak valid.'
                );
            }
        }

        $names = array_values(array_unique($names));

        try {
            DB::transaction(function () use ($names): void {
                foreach ($names as $name) {
                    Permission::findOrCreate($name, 'web');
                }
            }, attempts: 3);
        } finally {
            app(PermissionRegistrar::class)
                ->forgetCachedPermissions();
        }
    }
}
