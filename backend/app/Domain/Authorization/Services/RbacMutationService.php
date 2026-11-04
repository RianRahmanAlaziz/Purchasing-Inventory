<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

final class RbacMutationService
{
    /**
     * All RBAC write endpoints must use this method to serialize mutations,
     * then validate that the actor and at least one active admin retain access.
     */
    public function execute(User $actor, Closure $operation): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $operation): mixed {
                $mutex = DB::table('rbac_operation_locks')
                    ->where('id', 1)
                    ->lockForUpdate()
                    ->first();

                if ($mutex === null) {
                    throw new LogicException('RBAC operation lock is missing.');
                }

                $result = $operation();

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $freshActor = $actor->fresh();
                if ($freshActor === null || ! $freshActor->is_active || ! $freshActor->can('roles.manage')) {
                    abort(409, 'Perubahan ini akan mencabut akses pengelolaan milik Anda.');
                }

                if (! $this->activeManagerExists()) {
                    abort(409, 'Setidaknya satu pengelola role yang aktif harus tetap ada.');
                }

                return $result;
            }, 3);
        } finally {
            // A failed transaction must not leave stale Spatie permission cache.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function activeManagerExists(): bool
    {
        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereHas('roles.permissions', function (Builder $permission): void {
                    $permission->where('permissions.name', 'roles.manage')
                        ->where('permissions.guard_name', 'web');
                })->orWhereHas('permissions', function (Builder $permission): void {
                    $permission->where('permissions.name', 'roles.manage')
                        ->where('permissions.guard_name', 'web');
                });
            })
            ->exists();
    }
}
