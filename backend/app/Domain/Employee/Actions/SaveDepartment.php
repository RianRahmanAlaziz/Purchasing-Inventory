<?php

declare(strict_types=1);

namespace App\Domain\Employee\Actions;

use App\Domain\Employee\Models\Department;

final class SaveDepartment
{
    /** @param array{code:string,name:string,is_active?:bool|int|string} $data */
    public function create(array $data): Department
    {
        return Department::query()->create($data);
    }

    /** @param array{code?:string,name?:string,is_active?:bool|int|string} $data */
    public function update(Department $department, array $data): Department
    {
        $department->update($data);

        return $department->refresh();
    }
}
