<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RbacHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function loginAsManager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_role_change_is_recorded_in_audit_log(): void
    {
        $this->loginAsManager();

        $response = $this->postJson('/api/v1/authorization/roles', [
            'name' => 'auditor',
            'permissions' => ['reports.view'],
        ])->assertCreated();

        $this->assertDatabaseHas('rbac_audit_logs', [
            'event' => 'role.created',
            'subject_type' => 'role',
            'subject_id' => (string) $response->json('data.id'),
        ]);

        $this->getJson('/api/v1/authorization/audit-logs')->assertOk();
    }

    public function test_last_manager_cannot_remove_own_management_permission(): void
    {
        $this->loginAsManager();
        $role = Role::findByName('super_admin', 'web');

        $this->patchJson("/api/v1/authorization/roles/{$role->id}", [
            'permissions' => ['reports.view'],
        ])->assertStatus(409);

        $this->assertTrue(
            Role::findByName('super_admin', 'web')->hasPermissionTo('roles.manage')
        );

        $this->assertDatabaseMissing('rbac_audit_logs', [
            'event' => 'role.updated',
            'subject_id' => (string) $role->id,
        ]);
    }

    public function test_permission_display_name_can_change_without_renaming_technical_key(): void
    {
        $this->loginAsManager();
        $id = Permission::findByName('reports.view', 'web')->id;

        $this->patchJson("/api/v1/authorization/permissions/{$id}", [
            'display_name' => 'Lihat laporan',
            'description' => 'Akses baca untuk laporan operasional',
        ])->assertOk()->assertJsonPath('data.name', 'reports.view')
            ->assertJsonPath('data.display_name', 'Lihat laporan');

        $this->assertDatabaseHas('rbac_audit_logs', [
            'event' => 'permission.updated',
            'subject_id' => (string) $id,
        ]);
    }

    public function test_role_assignment_revokes_target_tokens_and_keeps_audit(): void
    {
        $this->loginAsManager();
        $target = User::factory()->create(['is_active' => true]);
        $target->createToken('old-session');
        $this->assertSame(1, $target->tokens()->count());

        $this->putJson("/api/v1/authorization/users/{$target->id}/roles", [
            'roles' => ['staff_hr'],
        ])->assertOk();

        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue($target->fresh()->hasRole('staff_hr'));
        $this->assertDatabaseHas('rbac_audit_logs', [
            'event' => 'user.roles.synced',
            'subject_id' => (string) $target->id,
        ]);
    }

    public function test_staff_hr_cannot_view_audit_log(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('staff_hr');
        Sanctum::actingAs($staff, ['*']);

        $this->getJson('/api/v1/authorization/audit-logs')->assertForbidden();
    }
}
