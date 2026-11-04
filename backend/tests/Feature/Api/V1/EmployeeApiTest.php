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

final class EmployeeApiTest extends TestCase
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

        Permission::findOrCreate('employees.view', 'web');
        Permission::findOrCreate('employees.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function loginWithPermissions(array $permissions): User
    {
        $role = Role::findOrCreate('employee_api_test_role', 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function department(
        string $code = 'HRD',
        bool $active = true
    ): Department {
        return Department::query()->create([
            'code' => $code,
            'name' => 'Departemen '.$code,
            'is_active' => $active,
        ]);
    }

    private function employee(
        Department $department,
        string $number = 'EMP-001',
        ?User $user = null,
        bool $active = true
    ): Employee {
        return Employee::query()->create([
            'department_id' => $department->id,
            'user_id' => $user?->id,
            'employee_number' => $number,
            'name' => 'Karyawan '.$number,
            'is_active' => $active,
        ]);
    }

    public function test_guest_cannot_access_employee_list(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
    }

    public function test_view_only_role_can_read_but_not_create(): void
    {
        $this->loginWithPermissions(['employees.view']);
        $employee = $this->employee($this->department());

        $this->getJson('/api/v1/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.employee_number', 'EMP-001');

        $this->postJson('/api/v1/employees', [
            'department_id' => $employee->department_id,
            'employee_number' => 'EMP-002',
            'name' => 'Karyawan Baru',
        ])->assertForbidden();
    }

    public function test_manager_can_create_employee_and_link_existing_account(): void
    {
        $this->loginWithPermissions(['employees.view', 'employees.manage']);
        $department = $this->department();
        $account = User::factory()->create(['is_active' => true]);

        $this->postJson('/api/v1/employees', [
            'department_id' => $department->id,
            'user_id' => $account->id,
            'employee_number' => ' emp-091 ',
            'name' => '  Siti   Aminah ',
            'email' => ' SITI@example.test ',
        ])->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-091')
            ->assertJsonPath('data.name', 'Siti Aminah')
            ->assertJsonPath('data.email', 'siti@example.test')
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.user.id', $account->id);

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'EMP-091',
            'user_id' => $account->id,
        ]);
        $this->assertCount(0, $account->getRoleNames());
    }

    public function test_duplicate_employee_number_is_rejected(): void
    {
        $this->loginWithPermissions(['employees.manage']);
        $department = $this->department();
        $this->employee($department, 'EMP-001');

        $this->postJson('/api/v1/employees', [
            'department_id' => $department->id,
            'employee_number' => 'emp-001',
            'name' => 'Duplikat',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_number');
    }

    public function test_inactive_department_cannot_be_selected(): void
    {
        $this->loginWithPermissions(['employees.manage']);
        $department = $this->department('OLD', false);

        $this->postJson('/api/v1/employees', [
            'department_id' => $department->id,
            'employee_number' => 'EMP-002',
            'name' => 'Karyawan Baru',
        ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_an_account_cannot_be_linked_to_two_employees(): void
    {
        $this->loginWithPermissions(['employees.manage']);
        $department = $this->department();
        $account = User::factory()->create(['is_active' => true]);
        $this->employee($department, 'EMP-001', $account);

        $this->postJson('/api/v1/employees', [
            'department_id' => $department->id,
            'user_id' => $account->id,
            'employee_number' => 'EMP-002',
            'name' => 'Karyawan Kedua',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_manager_can_deactivate_employee_and_revoke_linked_tokens(): void
    {
        $this->loginWithPermissions(['employees.view', 'employees.manage']);
        $department = $this->department();
        $account = User::factory()->create(['is_active' => true]);
        $employee = $this->employee($department, 'EMP-001', $account);
        $account->createToken('employee-self-test', ['employee:self']);

        $this->assertSame(1, $account->tokens()->count());

        $this->patchJson('/api/v1/employees/'.$employee->id, [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $account->tokens()->count());
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'is_active' => 0,
        ]);
    }

    public function test_list_supports_search_and_active_filter(): void
    {
        $this->loginWithPermissions(['employees.view']);
        $department = $this->department();
        $this->employee($department, 'EMP-001', active: true);
        $this->employee($department, 'EMP-002', active: false);

        $this->getJson('/api/v1/employees?search=EMP&is_active=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_number', 'EMP-001')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_employee_with_distribution_history_cannot_be_deleted(): void
    {
        $actor = $this->loginWithPermissions(['employees.manage']);
        $department = $this->department();
        $employee = $this->employee($department);

        DB::table('distributions')->insert([
            'distribution_number' => 'DIST-TEST-001',
            'employee_id' => $employee->id,
            'requested_by' => $actor->id,
            'purpose' => 'Pengujian histori distribusi',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson('/api/v1/employees/'.$employee->id)
            ->assertStatus(409);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_unused_employee_can_be_deleted(): void
    {
        $this->loginWithPermissions(['employees.manage']);
        $employee = $this->employee($this->department());

        $this->deleteJson('/api/v1/employees/'.$employee->id)
            ->assertOk();

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_employee_self_service_user_cannot_use_management_api(): void
    {
        $department = $this->department();
        $account = User::factory()->create(['is_active' => true]);
        $employee = $this->employee($department, 'EMP-001', $account);

        Sanctum::actingAs($account, ['employee:self']);

        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->patchJson('/api/v1/employees/'.$employee->id, [
            'name' => 'Tidak Diizinkan',
        ])->assertForbidden();
    }
}
