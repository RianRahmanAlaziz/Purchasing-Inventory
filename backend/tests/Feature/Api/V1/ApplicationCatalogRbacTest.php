<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ApplicationCatalogRbacTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [
                'roles.manage',
                'po.view',
                'inventory.view',
                'reports.view',
            ] as $name
        ) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }

    private function createRole(array $permissions = []): Role
    {
        $role = Role::create([
            'name' => 'erp_test_' . strtolower(Str::random(16)),
            'guard_name' => 'web',
        ]);

        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }

        return $role;
    }

    private function createUser(Role $role): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function login(User $user): void
    {
        // Fresh user mensimulasikan request baru,
        // tanpa relasi role yang mungkin sudah di-cache.
        Sanctum::actingAs($user->fresh(), ['*']);
    }

    public function test_revoking_role_permission_hides_app(): void
    {
        $manager = $this->createUser(
            $this->createRole(['roles.manage'])
        );

        $role = $this->createRole([
            'po.view',
            'inventory.view',
        ]);

        $employee = $this->createUser($role);

        $this->login($employee);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', 'purchasing')
            ->assertJsonPath('data.1.key', 'inventory');

        // Admin mencabut akses Inventory melalui API RBAC.
        $this->login($manager);

        $this->patchJson(
            "/api/v1/authorization/roles/{$role->id}",
            ['permissions' => ['po.view']]
        )->assertOk();

        // Request berikutnya harus membaca hak akses terbaru.
        $this->login($employee);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'purchasing');

        // Menyembunyikan aplikasi tidak cukup.
        // API Inventory juga harus menolak akses.
        $this->getJson('/api/v1/stock-movements')
            ->assertForbidden();
    }

    public function test_granting_permission_shows_new_app(): void
    {
        $manager = $this->createUser(
            $this->createRole(['roles.manage'])
        );

        $role = $this->createRole();

        $employee = $this->createUser($role);

        $this->login($employee);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Admin menambahkan akses Inventory.
        $this->login($manager);

        $this->patchJson(
            "/api/v1/authorization/roles/{$role->id}",
            ['permissions' => ['inventory.view']]
        )->assertOk();

        $this->login($employee);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'inventory');

        $this->getJson('/api/v1/stock-movements')
            ->assertOk();
    }

    public function test_direct_user_permission_is_recognized(): void
    {
        $employee = $this->createUser(
            $this->createRole()
        );

        $employee->givePermissionTo('reports.view');

        $this->login($employee);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'reporting');
    }
}
