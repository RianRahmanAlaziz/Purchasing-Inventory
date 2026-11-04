<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DepartmentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name
        );

        Permission::findOrCreate('departments.view', 'web');
        Permission::findOrCreate('departments.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param list<string> $permissions */
    private function actAsRoleWithPermissions(array $permissions): void
    {
        $roleName = in_array('departments.manage', $permissions, true)
            ? 'department_editor_test'
            : 'department_reader_test';

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);
    }

    private function department(string $code, string $name, bool $active = true): Department
    {
        return Department::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/v1/departments')->assertUnauthorized();
    }

    public function test_read_only_role_can_view_but_cannot_create(): void
    {
        $this->actAsRoleWithPermissions(['departments.view']);
        $department = $this->department('HRD', 'Human Resources');

        $this->getJson('/api/v1/departments/'.$department->id)
            ->assertOk()
            ->assertJsonPath('data.code', 'HRD');

        $this->postJson('/api/v1/departments', [
            'code' => 'FIN',
            'name' => 'Finance',
        ])->assertForbidden();
    }

    public function test_role_with_manage_permission_can_create_department(): void
    {
        $this->actAsRoleWithPermissions(['departments.view', 'departments.manage']);

        $this->postJson('/api/v1/departments', [
            'code' => ' hr-ops ',
            'name' => '  Human   Resource  Operations ',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'HR-OPS')
            ->assertJsonPath('data.name', 'Human Resource Operations');

        $this->assertDatabaseHas('departments', [
            'code' => 'HR-OPS',
            'name' => 'Human Resource Operations',
        ]);
    }

    public function test_duplicate_department_code_returns_422(): void
    {
        $this->actAsRoleWithPermissions(['departments.view', 'departments.manage']);
        $this->department('HRD', 'Human Resources');

        $this->postJson('/api/v1/departments', [
            'code' => 'hrd',
            'name' => 'HR Baru',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_department_can_be_updated_and_deactivated(): void
    {
        $this->actAsRoleWithPermissions(['departments.view', 'departments.manage']);
        $department = $this->department('OPS', 'Operations');

        $this->patchJson('/api/v1/departments/'.$department->id, [
            'name' => 'Operational Support',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Operational Support')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_list_can_search_and_filter_active_departments(): void
    {
        $this->actAsRoleWithPermissions(['departments.view']);
        $this->department('FIN', 'Finance', true);
        $this->department('FINOLD', 'Finance Legacy', false);
        $this->department('HRD', 'Human Resources', true);

        $this->getJson('/api/v1/departments?search=Finance&is_active=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'FIN')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_department_used_by_employee_cannot_be_deleted(): void
    {
        $this->actAsRoleWithPermissions(['departments.view', 'departments.manage']);
        $department = $this->department('HRD', 'Human Resources');

        Employee::query()->create([
            'department_id' => $department->id,
            'employee_number' => 'EMP-DEPT-01',
            'name' => 'Karyawan Uji',
            'is_active' => true,
        ]);

        $this->deleteJson('/api/v1/departments/'.$department->id)
            ->assertStatus(409);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_unused_department_can_be_deleted(): void
    {
        $this->actAsRoleWithPermissions(['departments.view', 'departments.manage']);
        $department = $this->department('TEMP', 'Temporary Department');

        $this->deleteJson('/api/v1/departments/'.$department->id)
            ->assertOk();

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }
}
