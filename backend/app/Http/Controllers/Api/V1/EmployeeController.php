<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Employee\Actions\DeleteEmployee;
use App\Domain\Employee\Actions\SaveEmployee;
use App\Domain\Employee\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Employee\StoreEmployeeRequest;
use App\Http\Requests\V1\Employee\UpdateEmployeeRequest;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'department_id' => ['sometimes', 'integer', Rule::exists('departments', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Employee::query()->with([
            'department:id,code,name',
            'user:id,name,email,is_active',
        ]);

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);

            $query->where(static function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var(
                $filters['is_active'],
                FILTER_VALIDATE_BOOLEAN
            ));
        }

        $page = $query->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        $items = $page->getCollection()
            ->map(
                static fn (Employee $employee): array => (new EmployeeResource($employee))->toArray($request)
            )->values()->all();

        return ApiResponse::success(
            data: $items,
            message: 'Daftar karyawan berhasil diambil.',
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

    public function show(Request $request, Employee $employee): JsonResponse
    {
        return ApiResponse::success(
            data: (new EmployeeResource(
                $employee->load(['department', 'user'])
            ))->toArray($request),
            message: 'Detail karyawan berhasil diambil.',
        );
    }

    public function store(
        StoreEmployeeRequest $request,
        SaveEmployee $action
    ): JsonResponse {
        $employee = $action->create($request->validated());

        return ApiResponse::success(
            data: (new EmployeeResource($employee))->toArray($request),
            message: 'Karyawan berhasil ditambahkan.',
            status: 201,
        );
    }

    public function update(
        UpdateEmployeeRequest $request,
        Employee $employee,
        SaveEmployee $action
    ): JsonResponse {
        $employee = $action->update($employee, $request->validated());

        return ApiResponse::success(
            data: (new EmployeeResource($employee))->toArray($request),
            message: 'Data karyawan berhasil diperbarui.',
        );
    }

    public function destroy(
        Employee $employee,
        DeleteEmployee $action
    ): JsonResponse {
        if (! $action->execute($employee)) {
            return ApiResponse::error(
                'Karyawan tidak dapat dihapus karena memiliki riwayat PO atau distribusi.',
                409,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'Karyawan berhasil dihapus.',
        );
    }
}
