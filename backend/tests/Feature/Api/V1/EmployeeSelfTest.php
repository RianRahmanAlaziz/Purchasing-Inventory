<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EmployeeSelfTest extends TestCase
{
    use DatabaseTransactions;

    public function test_employee_without_spatie_role_can_read_only_self_profile(): void
    {
        $department = Department::query()->create([
            'code' => 'HR',
            'name' => 'Human Resources',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create([
            'is_active' => true,
        ]);

        $mine = Employee::query()->create([
            'department_id' => $department->id,
            'user_id' => $user->id,
            'employee_number' => 'EMP-001',
            'name' => 'Karyawan Satu',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'department_id' => $department->id,
            'user_id' => $otherUser->id,
            'employee_number' => 'EMP-002',
            'name' => 'Karyawan Dua',
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $login->assertOk();
        $this->assertIsString($login->json('data.token'));

        Sanctum::actingAs($user, ['employee:self']);

        $this->getJson('/api/v1/employees/me')
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.name', 'Karyawan Satu');

        $this->getJson('/api/v1/authorization/roles')
            ->assertForbidden();
    }

    public function test_inactive_employee_cannot_access_self_service(): void
    {
        $department = Department::query()->create([
            'code' => 'GA',
            'name' => 'General Affairs',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        Employee::query()->create([
            'department_id' => $department->id,
            'user_id' => $user->id,
            'employee_number' => 'EMP-003',
            'name' => 'Karyawan Nonaktif',
            'is_active' => false,
        ]);

        Sanctum::actingAs($user, ['employee:self']);

        $this->getJson('/api/v1/employees/me')
            ->assertForbidden();
    }
}
