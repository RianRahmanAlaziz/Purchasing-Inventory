<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authorization\SavePermissionRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

final class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name']);

        return ApiResponse::success(
            $permissions,
            'Daftar permission berhasil diambil.'
        );
    }

    public function store(
        SavePermissionRequest $request
    ): JsonResponse {
        $permission = Permission::create([
            'name' => $request->validated('name'),
            'guard_name' => 'web',
        ]);

        return ApiResponse::success(
            [
                'id' => $permission->id,
                'name' => $permission->name,
            ],
            'Permission berhasil dibuat.',
            201
        );
    }
}
