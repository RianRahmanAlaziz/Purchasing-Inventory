<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = ['companies.view', 'companies.manage', 'invoices.view', 'invoices.manage',
            'payments.view', 'payments.manage', 'payments.verify', 'documents.view',
            'documents.manage', 'documents.send', 'documents.receive', 'documents.verify'];
        DB::transaction(function () use ($permissions): void {
            $adminRoles = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
                ->where('permissions.name', 'roles.manage')->where('permissions.guard_name', 'web')->pluck('role_id');
            $poRoles = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
                ->where('permissions.name', 'po.view')->where('permissions.guard_name', 'web')->pluck('role_id');
            foreach ($permissions as $name) {
                $permission = Permission::findOrCreate($name, 'web');
                $roles = $name === 'companies.view' ? $adminRoles->merge($poRoles)->unique() : $adminRoles;
                foreach ($roles as $roleId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission->id]);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve assignments made through RBAC after deployment when rolling back schema.
    }
};
