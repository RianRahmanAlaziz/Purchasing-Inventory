<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ApplicationCatalogApiTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $role = Role::create([
            'name' => 'erp-test-' . Str::ulid(),
            'guard_name' => 'web',
        ]);

        foreach ($permissions as $name) {
            $role->givePermissionTo(
                Permission::findOrCreate($name, 'web')
            );
        }

        $user->assignRole($role);

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        return $user;
    }

    public function test_guest_cannot_access_erp_apps(): void
    {
        $this->getJson('/api/v1/erp/apps')
            ->assertUnauthorized();
    }

    public function test_user_without_permissions_gets_empty_list(): void
    {
        $user = $this->userWithPermissions([]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_purchasing_user_sees_only_purchasing(): void
    {
        $user = $this->userWithPermissions(['po.view']);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'purchasing');
    }

    public function test_multiple_permissions_show_multiple_apps(): void
    {
        $user = $this->userWithPermissions([
            'po.view',
            'inventory.view',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', 'purchasing')
            ->assertJsonPath('data.1.key', 'inventory')
            ->assertJsonMissing(['key' => 'maintenance']);
    }

    public function test_employee_self_sees_distribution(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $department = Department::create([
            'code' => 'ERP-' . Str::ulid(),
            'name' => 'Department ERP Test',
            'is_active' => true,
        ]);

        Employee::create([
            'department_id' => $department->id,
            'user_id' => $user->id,
            'employee_number' => 'ERP-' . Str::ulid(),
            'name' => 'Employee ERP Test',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['employee:self']);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'distribution');
    }

    public function test_inactive_user_is_forbidden(): void
    {
        $user = $this->userWithPermissions(['po.view']);
        $user->update(['is_active' => false]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/erp/apps')
            ->assertForbidden();
    }
}
