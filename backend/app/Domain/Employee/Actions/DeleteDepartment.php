<?php

declare(strict_types=1);

namespace App\Domain\Employee\Actions;

use App\Domain\Employee\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteDepartment
{
    public function execute(Department $department): bool
    {
        try {
            return DB::transaction(function () use ($department): bool {
                $locked = Department::query()
                    ->lockForUpdate()
                    ->findOrFail($department->getKey());

                if ($locked->employees()->exists()) {
                    return false;
                }

                // PO dapat berstatus soft-deleted; FK tetap mempertahankan relasinya.
                if (DB::table('purchase_orders')
                    ->where('requested_for_department_id', $locked->getKey())
                    ->exists()) {
                    return false;
                }

                return (bool) $locked->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            // MySQL 1451: FK RESTRICT mencegah penghapusan parent.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                return false;
            }

            throw $exception;
        }
    }
}
