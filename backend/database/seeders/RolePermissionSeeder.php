<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {

        if (
            Role::query()
                ->where('guard_name', 'web')
                ->exists()
        ) {
            $this->command?->warn('RBAC sudah diinisialisasi. Seeder dilewati agar perubahan role dan permission tidak tertimpa.');

            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'users.view',
            'users.manage',
            'roles.manage',
            'departments.view',
            'departments.manage',
            'employees.view',
            'employees.manage',
            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'items.view',
            'items.manage',
            'taxes.view',
            'taxes.manage',
            'po.view',
            'po.create',
            'po.update',
            'po.submit',
            'po.send',
            'po.supplier-confirm',
            'po.approve.hr',
            'po.reject.hr',
            'po.approve.gm',
            'po.reject.gm',
            'grn.view',
            'grn.create',
            'grn.confirm',
            'inventory.view',
            'inventory.adjust',
            'distribution.view',
            'distribution.prepare',
            'distribution.send',
            'distribution.approve',
            'distribution.reject',
            'reports.view',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $roles = [
            'super_admin' => $permissions,

            'general_manager' => [
                'po.view',
                'po.approve.gm',
                'po.reject.gm',
                'distribution.view',
                'distribution.approve',
                'distribution.reject',
                'inventory.view',
                'reports.view',
            ],

            'hr' => [
                'departments.view',
                'departments.manage',
                'employees.view',
                'employees.manage',
                'suppliers.view',
                'suppliers.create',
                'suppliers.update',
                'items.view',
                'items.manage',
                'taxes.view',
                'po.view',
                'po.create',
                'po.update',
                'po.submit',
                'po.send',
                'po.supplier-confirm',
                'po.approve.hr',
                'po.reject.hr',
                'grn.view',
                'grn.create',
                'grn.confirm',
                'inventory.view',
                'inventory.adjust',
                'distribution.view',
                'distribution.prepare',
                'reports.view',
            ],

            'staff_hr' => [
                'departments.view',
                'employees.view',
                'suppliers.view',
                'suppliers.create',
                'suppliers.update',
                'items.view',
                'taxes.view',
                'po.view',
                'po.create',
                'po.update',
                'po.submit',
                'po.send',
                'po.supplier-confirm',
                'grn.view',
                'grn.create',
                'inventory.view',
                'distribution.view',
                'distribution.prepare',
                'distribution.send',
            ],
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::findOrCreate($name, 'web');
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
