<?php

declare(strict_types=1);

namespace App\Domain\Employee\Actions;

use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteEmployee
{
    public function execute(Employee $employee): bool
    {
        try {
            return DB::transaction(function () use ($employee): bool {
                $locked = Employee::query()
                    ->lockForUpdate()
                    ->findOrFail($employee->getKey());

                // Termasuk PO yang di-soft-delete: histori tetap harus dijaga.
                if (DB::table('purchase_orders')
                    ->where('requested_for_employee_id', $locked->getKey())
                    ->exists()) {
                    return false;
                }

                if (DB::table('distributions')
                    ->where('employee_id', $locked->getKey())
                    ->exists()) {
                    return false;
                }

                $userId = $locked->user_id;
                $locked->delete();

                if ($userId !== null) {
                    User::query()->find($userId)?->tokens()->delete();
                }

                return true;
            }, attempts: 3);
        } catch (QueryException $exception) {
            // MySQL error 1451: parent row masih dipakai tabel lain.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                return false;
            }

            throw $exception;
        }
    }
}
