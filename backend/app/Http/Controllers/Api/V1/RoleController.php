<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\Services\RbacAuditLogger;
use App\Domain\Authorization\Services\RbacMutationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authorization\SaveRoleRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
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
            ->map(fn (Role $role): array => $this->format($role));

        return ApiResponse::success($roles, 'Daftar role berhasil diambil.');
    }

    public function store(
        SaveRoleRequest $request,
        RbacMutationService $mutation,
        RbacAuditLogger $audit
    ): JsonResponse {
        $data = $request->validated();
        $actor = $request->user();

        $result = $mutation->execute($actor, function () use ($request, $actor, $data, $audit): array {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions']);
            $after = $this->format($role->fresh('permissions'));
            $audit->record($actor, $request, 'role.created', 'role', $role->id, null, $after);

            return $after;
        });

        return ApiResponse::success($result, 'Role berhasil dibuat.', 201);
    }

    public function update(
        SaveRoleRequest $request,
        Role $role,
        RbacMutationService $mutation,
        RbacAuditLogger $audit
    ): JsonResponse {
        abort_unless($role->guard_name === 'web', 404);
        $data = $request->validated();
        $actor = $request->user();

        $result = $mutation->execute($actor, function () use ($request, $actor, $role, $data, $audit): array {
            $lockedRole = Role::query()
                ->whereKey($role->getKey())
                ->where('guard_name', 'web')
                ->firstOrFail();

            $before = $this->format($lockedRole->load('permissions'));

            if (array_key_exists('name', $data)) {
                $lockedRole->update(['name' => $data['name']]);
            }
            if (array_key_exists('permissions', $data)) {
                $lockedRole->syncPermissions($data['permissions']);
            }

            $after = $this->format($lockedRole->fresh('permissions'));
            $audit->record($actor, $request, 'role.updated', 'role', $lockedRole->id, $before, $after);

            return $after;
        });

        return ApiResponse::success($result, 'Role berhasil diperbarui.');
    }

    /** @return array{id: mixed, name: string, permissions: array<int, string>} */
    private function format(Role $role): array
    {
        return [
            'id' => $role->getKey(),
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }
}
