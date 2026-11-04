<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmployeeSelfController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->user()->tokenCan('employee:self')) {
            return ApiResponse::error(
                'Token tidak memiliki akses self-service.',
                403
            );
        }

        $employee = $request->user()->employee()
            ->where('is_active', true)
            ->with('department:id,code,name')
            ->first();

        if ($employee === null) {
            return ApiResponse::error(
                'Data karyawan aktif tidak ditemukan.',
                403
            );
        }

        return ApiResponse::success(
            data: [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
                'department' => $employee->department ? [
                    'id' => $employee->department->id,
                    'code' => $employee->department->code,
                    'name' => $employee->department->name,
                ] : null,
            ],
            message: 'Identitas karyawan berhasil diambil.',
        );
    }
}
