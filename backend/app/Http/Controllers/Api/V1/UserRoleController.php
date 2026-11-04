<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\Services\RbacAuditLogger;
use App\Domain\Authorization\Services\RbacMutationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authorization\SyncUserRolesRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class UserRoleController extends Controller
{
    public function update(
        SyncUserRolesRequest $request,
        User $user,
        RbacMutationService $mutation,
        RbacAuditLogger $audit
    ): JsonResponse {
        $actor = $request->user();
        $roles = $request->validated('roles');

        $result = $mutation->execute($actor, function () use ($request, $actor, $user, $roles, $audit): array {
            $target = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $before = ['roles' => $target->getRoleNames()->sort()->values()->all()];

            $target->syncRoles($roles);
            $after = ['roles' => $target->fresh()->getRoleNames()->sort()->values()->all()];

            $audit->record($actor, $request, 'user.roles.synced', 'user', $target->id, $before, $after);

            // Old bearer tokens are no longer accepted after assignment changes.
            $target->tokens()->delete();

            return ['user_id' => $target->getKey(), ...$after];
        });

        return ApiResponse::success($result, 'Role pengguna berhasil diperbarui.');
    }
}
