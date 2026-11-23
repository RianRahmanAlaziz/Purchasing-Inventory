<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Models\User;

final class ApplicationCatalog
{
    public function visibleTo(User $user): array
    {
        $definitions = config('erp.applications', []);
        $visible = [];

        foreach ($definitions as $key => $app) {
            if (
                ! is_string($key)
                || ! is_array($app)
                || ($app['enabled'] ?? false) !== true
            ) {
                continue;
            }

            $permissions = $app['permissions'] ?? [];

            $allowed = is_array($permissions)
                && $permissions !== []
                && $user->canAny($permissions);

            // Pertahankan akses khusus Employee
            // dari implementasi ApplicationCatalog sebelumnya.
            if ($key === 'distribution') {
                $allowed = $allowed || (
                    $user->tokenCan('employee:self')
                    && $user->employee()
                    ->where('is_active', true)
                    ->exists()
                );
            }

            if (! $allowed) {
                continue;
            }

            $visible[] = [
                'key' => $key,
                'name' => $app['name'],
                'description' => $app['description'] ?? '',
                'icon' => $app['icon'] ?? 'layout-grid',
                'order' => (int) ($app['order'] ?? 999),
            ];
        }

        usort(
            $visible,
            static fn(array $a, array $b): int => ($a['order'] <=> $b['order'])
                ?: strcmp($a['key'], $b['key'])
        );

        return array_map(
            static fn(array $app): array => [
                'key' => $app['key'],
                'name' => $app['name'],
                'description' => $app['description'],
                'icon' => $app['icon'],
            ],
            $visible
        );
    }
}
