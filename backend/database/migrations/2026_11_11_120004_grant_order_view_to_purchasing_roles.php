<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::findOrCreate('orders.view', 'web');
            foreach ($this->roles()->get() as $role) {
                $role->givePermissionTo($permission);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->where('name', 'orders.view')->where('guard_name', 'web')->first();
            if ($permission === null) {
                return;
            }

            foreach ($this->roles()->get() as $role) {
                $role->revokePermissionTo($permission);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roles(): Builder
    {
        return Role::query()->where('guard_name', 'web')->whereIn('name', ['staff_hr', 'hr', 'general_manager']);
    }
};
