<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authorization\SaveRoleRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn(Role $role) => $this->format($role));

        return ApiResponse::success(
            $roles,
            'Daftar role berhasil diambil.'
        );
    }

    public function store(
        SaveRoleRequest $request
    ): JsonResponse {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data): Role {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($data['permissions']);

            return $role->load('permissions');
        });

        return ApiResponse::success(
            $this->format($role),
            'Role berhasil dibuat.',
            201
        );
    }

    public function update(
        SaveRoleRequest $request,
        Role $role
    ): JsonResponse {
        abort_unless($role->guard_name === 'web', 404);

        $data = $request->validated();

        // Cegah pengguna mengunci akses manajemen role
        // miliknya sendiri secara tidak sengaja.
        if (array_key_exists('permissions', $data)) {
            $actor = $request->user();

            $removesManage = $role->hasPermissionTo('roles.manage')
                && ! in_array(
                    'roles.manage',
                    $data['permissions'],
                    true
                );

            $hasOtherManage = $actor->getDirectPermissions()
                ->contains('name', 'roles.manage')
                || $actor->roles
                ->filter(
                    fn(Role $assigned) =>
                    $assigned->getKey() !== $role->getKey()
                )
                ->contains(
                    fn(Role $assigned) =>
                    $assigned->hasPermissionTo('roles.manage')
                );

            if (
                $removesManage &&
                $actor->hasRole($role) &&
                ! $hasOtherManage
            ) {
                return ApiResponse::error(
                    'Tidak boleh menghapus akses roles.manage milik sendiri tanpa akses pengganti.',
                    409
                );
            }
        }

        DB::transaction(function () use ($role, $data): void {
            if (array_key_exists('name', $data)) {
                $role->update(['name' => $data['name']]);
            }

            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions']);
            }
        });

        return ApiResponse::success(
            $this->format($role->fresh('permissions')),
            'Role berhasil diperbarui.'
        );
    }

    private function format(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
