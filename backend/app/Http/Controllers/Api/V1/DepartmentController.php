<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Employee\Actions\DeleteDepartment;
use App\Domain\Employee\Actions\SaveDepartment;
use App\Domain\Employee\Models\Department;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Department\StoreDepartmentRequest;
use App\Http\Requests\V1\Department\UpdateDepartmentRequest;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Department::query();

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->where(static function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where(
                'is_active',
                filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)
            );
        }

        $page = $query->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        $items = $page->getCollection()
            ->map(
                static fn (Department $department): array => (new DepartmentResource($department))->toArray($request)
            )->values()->all();

        return ApiResponse::success(
            data: $items,
            message: 'Daftar departemen berhasil diambil.',
            meta: [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        );
    }

    public function store(
        StoreDepartmentRequest $request,
        SaveDepartment $action
    ): JsonResponse {
        $department = $action->create($request->validated());

        return ApiResponse::success(
            data: (new DepartmentResource($department))->toArray($request),
            message: 'Departemen berhasil dibuat.',
            status: 201,
        );
    }

    public function show(Request $request, Department $department): JsonResponse
    {
        return ApiResponse::success(
            data: (new DepartmentResource($department))->toArray($request),
            message: 'Detail departemen berhasil diambil.',
        );
    }

    public function update(
        UpdateDepartmentRequest $request,
        Department $department,
        SaveDepartment $action
    ): JsonResponse {
        $updated = $action->update($department, $request->validated());

        return ApiResponse::success(
            data: (new DepartmentResource($updated))->toArray($request),
            message: 'Departemen berhasil diperbarui.',
        );
    }

    public function destroy(
        Department $department,
        DeleteDepartment $action
    ): JsonResponse {
        if (! $action->execute($department)) {
            return ApiResponse::error(
                'Departemen tidak dapat dihapus karena masih digunakan oleh karyawan atau PO.',
                409,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'Departemen berhasil dihapus.',
        );
    }
}
