<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'companies.view',
            'companies.manage',
            'invoices.view',
            'invoices.manage',
            'payments.view',
            'payments.manage',
            'payments.verify',
            'documents.view',
            'documents.manage',
            'documents.send',
            'documents.receive',
            'documents.verify',
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
            'suppliers.delete',
            'categories.view',
            'categories.manage',
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
            'orders.view',
            'grn.view',
            'grn.create',
            'grn.confirm',
            'inventory.view',
            'inventory.adjust',
            'distribution.view',
            'distribution.create',
            'distribution.prepare',
            'distribution.send',
            'distribution.approve',
            'distribution.reject',
            'reports.view',
        ];

        $roles = [
            'super_admin' => $permissions,

            'general_manager' => [
                'companies.view',
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
                'companies.view',
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
                'companies.view',
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

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        DB::transaction(function () use ($permissions, $roles): void {
            $existingRoles = Role::query()->where('guard_name', 'web')->lockForUpdate()->get();
            $first = $existingRoles->first();

            if ($first !== null && (
                $existingRoles->count() !== 1
                || $first->name !== 'super_admin'
                || $first->permissions()->exists()
                || $first->users()->exists()
            )) {
                $this->command?->warn('RBAC sudah diinisialisasi. Seeder dilewati agar perubahan role dan permission tidak tertimpa.');

                return;
            }

            $permissionsByName = [];
            foreach ($permissions as $name) {
                $permissionsByName[$name] = Permission::query()->firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                ]);
            }

            foreach ($roles as $name => $rolePermissions) {
                $role = Role::findOrCreate($name, 'web');
                $role->syncPermissions(array_map(
                    static fn (string $permission): Permission => $permissionsByName[$permission],
                    $rolePermissions,
                ));
            }
        }, attempts: 3);

        $registrar->forgetCachedPermissions();
    }
}
