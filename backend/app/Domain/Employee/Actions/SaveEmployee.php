<?php

declare(strict_types=1);

namespace App\Domain\Employee\Actions;

use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveEmployee
{
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data): Employee {
            $employee = Employee::query()->create($data);

            // Jika akun sebelumnya sudah login, paksa evaluasi akses ulang.
            if ($employee->user_id !== null) {
                User::query()->find($employee->user_id)?->tokens()->delete();
            }

            return $employee->load(['department', 'user']);
        }, attempts: 3);
    }

    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data): Employee {
            $locked = Employee::query()
                ->lockForUpdate()
                ->findOrFail($employee->getKey());

            $previousUserId = $locked->user_id;
            $wasActive = (bool) $locked->is_active;

            $locked->fill($data);
            $locked->save();

            $accountChanged = (string) $previousUserId !== (string) $locked->user_id;
            $deactivated = $wasActive && ! (bool) $locked->is_active;

            if ($accountChanged || $deactivated) {
                $ids = array_values(array_unique(array_filter(
                    [$previousUserId, $locked->user_id],
                    static fn (mixed $id): bool => $id !== null
                )));

                User::query()->whereIn('id', $ids)->get()
                    ->each(static function (User $user): void {
                        $user->tokens()->delete();
                    });
            }

            return $locked->refresh()->load(['department', 'user']);
        }, attempts: 3);
    }
}
