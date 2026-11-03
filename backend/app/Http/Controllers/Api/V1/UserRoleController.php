<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authorization\SyncUserRolesRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class UserRoleController extends Controller
{
    public function update(
        SyncUserRolesRequest $request,
        User $user
    ): JsonResponse {
        $roles = $request->validated('roles');

        // Cegah pengelola menghapus aksesnya sendiri.
        if ($user->is($request->user())) {
            $retainsManage = $user->getDirectPermissions()
                ->contains('name', 'roles.manage');

            if (! $retainsManage) {
                $retainsManage = Role::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', $roles)
                    ->get()
                    ->contains(
                        fn(Role $role) =>
                        $role->hasPermissionTo('roles.manage')
                    );
            }

            if (! $retainsManage) {
                return ApiResponse::error(
                    'Anda tidak dapat mencabut akses pengelolaan role milik sendiri.',
                    409
                );
            }
        }

        DB::transaction(function () use ($user, $roles): void {
            $user->syncRoles($roles);

            // Wajib login ulang setelah perubahan role.
            $user->tokens()->delete();
        });

        return ApiResponse::success(
            [
                'user_id' => $user->id,
                'roles' => $user->fresh()
                    ->getRoleNames()
                    ->values()
                    ->all(),
            ],
            'Role pengguna berhasil diperbarui.'
        );
    }
}
