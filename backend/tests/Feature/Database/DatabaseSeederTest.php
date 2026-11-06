<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DatabaseSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    }

    public function test_database_seed_creates_a_super_admin_with_module_permissions(): void
    {
        $password = 'testing-only-password-123';

        $this->artisan('db:seed')
            ->expectsQuestion('Email Super Admin (kosongkan untuk melewati)', '  BOOTSTRAP@example.com  ')
            ->expectsQuestion('Nama Super Admin', 'Bootstrap Admin')
            ->expectsQuestion('Password Super Admin (minimal 12 karakter)', $password)
            ->expectsQuestion('Ulangi password Super Admin', $password)
            ->assertSuccessful();

        $user = User::query()->where('email', 'bootstrap@example.com')->firstOrFail();
        $this->assertSame('Bootstrap Admin', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue($user->can('users.view'));
        $this->assertTrue($user->can('orders.view'));
    }

    public function test_database_seed_repairs_an_unassigned_super_admin_left_by_an_interrupted_seed(): void
    {
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.view', 'guard_name' => 'web']);
        $userCount = User::query()->count();

        $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();

        $this->assertTrue($role->fresh()->hasPermissionTo('users.view'));
        $this->assertTrue($role->fresh()->hasPermissionTo('roles.manage'));
        $this->assertDatabaseHas('roles', ['name' => 'staff_hr', 'guard_name' => 'web']);
        $this->assertDatabaseCount('users', $userCount);
    }

    public function test_reseeding_preserves_existing_admin_and_customized_role_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findByName('super_admin', 'web');
        $role->revokePermissionTo('users.view');
        $admin = User::factory()->create()->assignRole($role);
        $passwordHash = $admin->password;

        $this->artisan('db:seed')
            ->expectsQuestion('Email Super Admin (kosongkan untuk melewati)', $admin->email)
            ->assertSuccessful();

        $this->assertSame($passwordHash, $admin->fresh()->password);
        $this->assertFalse($role->fresh()->hasPermissionTo('users.view'));
    }

    public function test_database_seed_does_not_grant_permissions_to_an_assigned_empty_role(): void
    {
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();

        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $role->id]);
    }

    public function test_database_seed_refuses_to_promote_an_existing_user_by_email(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Email sudah digunakan oleh akun tanpa role super_admin.');

        try {
            $this->artisan('db:seed')
                ->expectsQuestion('Email Super Admin (kosongkan untuk melewati)', $user->email)
                ->run();
        } finally {
            $this->assertFalse($user->fresh()->hasRole('super_admin'));
        }
    }
}
