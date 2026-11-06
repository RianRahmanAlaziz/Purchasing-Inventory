<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\Services\RbacAuditLogger;
use App\Domain\Authorization\Services\RbacMutationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authorization\SavePermissionRequest;
use App\Http\Requests\V1\Authorization\UpdatePermissionRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

final class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            Permission::query()->where('guard_name', 'web')
                ->orderBy('name')->get(['id', 'name', 'display_name', 'description']),
            'Daftar permission berhasil diambil.'
        );
    }

    public function store(
        SavePermissionRequest $request,
        RbacMutationService $mutation,
        RbacAuditLogger $audit
    ): JsonResponse {
        $actor = $request->user();
        $attributes = $request->validated();

        $result = $mutation->execute($actor, function () use ($request, $actor, $attributes, $audit): array {
            $permission = Permission::create([...$attributes, 'guard_name' => 'web']);
            $data = $this->format($permission);
            $audit->record($actor, $request, 'permission.created', 'permission', $permission->id, null, $data);

            return $data;
        });

        return ApiResponse::success($result, 'Permission berhasil dibuat.', 201);
    }

    public function update(
        UpdatePermissionRequest $request,
        Permission $permission,
        RbacMutationService $mutation,
        RbacAuditLogger $audit
    ): JsonResponse {
        abort_unless($permission->guard_name === 'web', 404);
        $data = $request->validated();
        $actor = $request->user();

        $result = $mutation->execute($actor, function () use ($request, $actor, $permission, $data, $audit): array {
            $model = Permission::query()->whereKey($permission->id)
                ->where('guard_name', 'web')->firstOrFail();
            $before = $this->format($model);
            $model->forceFill($data)->save();
            $after = $this->format($model->fresh());
            $audit->record($actor, $request, 'permission.updated', 'permission', $model->id, $before, $after);

            return $after;
        });

        return ApiResponse::success($result, 'Metadata permission berhasil diperbarui.');
    }

    /** @return array{id: mixed, name: string, display_name: ?string, description: ?string} */
    private function format(Permission $permission): array
    {
        return [
            'id' => $permission->getKey(),
            'name' => $permission->name,
            'display_name' => $permission->display_name,
            'description' => $permission->description,
        ];
    }
}
