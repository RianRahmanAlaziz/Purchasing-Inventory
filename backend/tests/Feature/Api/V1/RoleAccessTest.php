<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RoleAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_cannot_read_roles(): void
    {
        $this->getJson('/api/v1/authorization/roles')
            ->assertUnauthorized();
    }

    public function test_staff_hr_cannot_manage_roles(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole('staff_hr');

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/authorization/roles')
            ->assertForbidden();
    }

    public function test_super_admin_can_read_roles(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole('super_admin');

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/authorization/roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'name' => 'super_admin',
            ]);
    }

    public function test_user_without_role_or_employee_cannot_enter_api(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_inactive_staff_cannot_use_existing_token(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
        ]);

        $user->assignRole('hr');

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }
}
