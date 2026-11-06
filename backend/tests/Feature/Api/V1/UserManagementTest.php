<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actorWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_account_operator', 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        $actor = User::factory()->create(['is_active' => true])->assignRole($role);
        Sanctum::actingAs($actor, ['*']);

        return $actor;
    }

    public function test_account_creation_assigns_roles_and_reports_effective_permissions_at_login(): void
    {
        $this->actorWithPermissions(['users.view', 'users.manage', 'roles.manage']);
        $role = Role::create(['name' => 'test_inventory_reader', 'guard_name' => 'web'])
            ->givePermissionTo('inventory.view');
        $password = 'test-password-only-123';

        $created = $this->postJson('/api/v1/users', [
            'name' => 'Account Fixture',
            'email' => ' ACCOUNT-FIXTURE@example.com ',
            'password' => $password,
            'password_confirmation' => $password,
            'roles' => [$role->name],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'account-fixture@example.com')
            ->assertJsonPath('data.roles', [$role->name])
            ->assertJsonMissingPath('data.password');

        $user = User::query()->findOrFail($created->json('data.id'));
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertDatabaseHas('rbac_audit_logs', [
            'event' => 'user.created',
            'subject_id' => (string) $user->id,
        ]);

        $this->getJson('/api/v1/users?search=ACCOUNT-FIXTURE&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonMissingPath('data.0.password');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('data.user.permissions', ['inventory.view']);
    }

    public function test_account_listing_requires_users_view(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();

        $this->actorWithPermissions(['roles.manage']);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_read_only_user_cannot_create_accounts_or_manage_rbac(): void
    {
        $this->actorWithPermissions(['users.view']);

        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->getJson('/api/v1/authorization/roles')->assertForbidden();
    }

    public function test_users_manage_alone_cannot_reset_an_admin_password(): void
    {
        $this->actorWithPermissions(['users.view', 'users.manage']);
        $admin = User::factory()->create(['is_active' => true])->assignRole('super_admin');

        $this->patchJson("/api/v1/users/{$admin->id}", [
            'password' => 'replacement-password-123',
            'password_confirmation' => 'replacement-password-123',
        ])->assertForbidden();

        $this->assertSame($admin->password, $admin->fresh()->password);
    }

    public function test_password_reset_revokes_tokens_and_keeps_password_out_of_audit_data(): void
    {
        $this->actorWithPermissions(['users.manage', 'roles.manage']);
        $user = User::factory()->create(['is_active' => true])->assignRole('staff_hr');
        $user->createToken('old-session');
        $password = 'replacement-password-123';

        $this->patchJson("/api/v1/users/{$user->id}", [
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk()->assertJsonMissingPath('data.password');

        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $audit = DB::table('rbac_audit_logs')->where('event', 'user.updated')->where('subject_id', (string) $user->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame(['is_active' => true], json_decode($audit->before_data, true));
        $this->assertSame(['is_active' => true, 'changed_fields' => ['password']], json_decode($audit->after_data, true));
    }

    public function test_deactivating_another_user_revokes_sessions_and_blocks_login(): void
    {
        $this->actorWithPermissions(['users.manage', 'roles.manage']);
        $password = 'test-password-only-123';
        $user = User::factory()->create(['is_active' => true, 'password' => $password])->assignRole('staff_hr');
        $user->createToken('old-session');

        $this->patchJson("/api/v1/users/{$user->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->assertUnauthorized();
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $actor = $this->actorWithPermissions(['users.manage', 'roles.manage']);
        $actor->createToken('current-session');

        $this->patchJson("/api/v1/users/{$actor->id}", ['is_active' => false])->assertConflict();

        $this->assertTrue($actor->fresh()->is_active);
        $this->assertSame(1, $actor->tokens()->count());
        $this->assertDatabaseMissing('rbac_audit_logs', ['event' => 'user.updated', 'subject_id' => (string) $actor->id]);
    }

    public function test_role_manager_can_remove_all_roles_from_another_user(): void
    {
        $this->actorWithPermissions(['roles.manage']);
        $user = User::factory()->create(['is_active' => true])->assignRole('staff_hr');
        $user->createToken('old-session');

        $this->putJson("/api/v1/authorization/users/{$user->id}/roles", ['roles' => []])
            ->assertOk()->assertJsonPath('data.roles', []);

        $this->assertCount(0, $user->fresh()->roles);
        $this->assertSame(0, $user->tokens()->count());
    }
}
