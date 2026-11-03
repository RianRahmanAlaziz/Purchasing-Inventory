<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DynamicRolePermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_can_create_role_and_permission(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/authorization/permissions', [
            'name' => 'reports.export',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'reports.export');

        $this->postJson('/api/v1/authorization/roles', [
            'name' => 'auditor',
            'permissions' => ['reports.export'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'auditor');

        $this->assertTrue(
            Role::findByName('auditor', 'web')
                ->hasPermissionTo('reports.export')
        );
    }

    public function test_manager_can_change_permissions_and_assign_role(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');

        Sanctum::actingAs($admin, ['*']);

        $role = Role::create([
            'name' => 'auditor',
            'guard_name' => 'web',
        ]);

        $this->patchJson(
            "/api/v1/authorization/roles/{$role->id}",
            [
                'permissions' => [
                    'reports.view',
                    'inventory.view',
                ],
            ]
        )->assertOk();

        $target = User::factory()->create([
            'is_active' => true,
        ]);

        $this->putJson(
            "/api/v1/authorization/users/{$target->id}/roles",
            ['roles' => ['auditor']]
        )->assertOk();

        $target = $target->fresh();

        $this->assertTrue($target->hasRole('auditor'));
        $this->assertTrue($target->can('reports.view'));
        $this->assertTrue($target->can('inventory.view'));

        // Cabut satu permission dari role.
        $this->patchJson(
            "/api/v1/authorization/roles/{$role->id}",
            ['permissions' => ['reports.view']]
        )->assertOk();

        $target->unsetRelation('roles');
        $target->unsetRelation('permissions');

        $this->assertFalse(
            $target->can('inventory.view')
        );
    }

    public function test_staff_hr_cannot_create_roles(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole('staff_hr');

        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/authorization/roles', [
            'name' => 'unauthorized_role',
            'permissions' => [],
        ])->assertForbidden();
    }

    public function test_guest_cannot_manage_roles(): void
    {
        $this->getJson('/api/v1/authorization/roles')
            ->assertUnauthorized();
    }
}
