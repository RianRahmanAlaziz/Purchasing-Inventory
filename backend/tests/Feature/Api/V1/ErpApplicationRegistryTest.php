<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Authorization\Services\ModulePermissionRegistrar;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ErpApplicationRegistryTest extends TestCase
{
    use DatabaseTransactions;

    private function loginWithPermissions(array $names): User
    {
        $role = Role::create([
            'name' => 'erp_registry_'
                . strtolower((string) Str::ulid()),
            'guard_name' => 'web',
        ]);

        foreach ($names as $name) {
            $role->givePermissionTo(
                Permission::findOrCreate($name, 'web')
            );
        }

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole($role);

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_catalog_returns_configured_metadata(): void
    {
        $this->loginWithPermissions(['po.view']);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'purchasing')
            ->assertJsonPath('data.0.name', 'Purchasing')
            ->assertJsonPath(
                'data.0.description',
                'Pengadaan dan purchase order.'
            )
            ->assertJsonPath(
                'data.0.icon',
                'shopping-cart'
            );
    }

    public function test_disabled_app_is_hidden(): void
    {
        $user = $this->loginWithPermissions([
            'inventory.view',
        ]);

        config()->set(
            'erp.applications.inventory.enabled',
            false
        );

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Registry visibility tidak mencabut permission.
        $this->assertTrue($user->can('inventory.view'));
    }

    public function test_new_module_can_be_registered_through_config(): void
    {
        app(ModulePermissionRegistrar::class)
            ->register(['maintenance.view']);

        config()->set('erp.applications.maintenance', [
            'name' => 'Maintenance',
            'description' => 'Perawatan aset dan mesin.',
            'icon' => 'wrench',
            'order' => 80,
            'enabled' => true,
            'permissions' => [
                'maintenance.view',
            ],
        ]);

        $this->loginWithPermissions([
            'maintenance.view',
        ]);

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'maintenance')
            ->assertJsonPath('data.0.name', 'Maintenance')
            ->assertJsonPath('data.0.icon', 'wrench');
    }

    public function test_application_order_follows_configuration(): void
    {
        $this->loginWithPermissions([
            'po.view',
            'inventory.view',
            'reports.view',
        ]);

        config()->set(
            'erp.applications.purchasing.order',
            90
        );

        $this->getJson('/api/v1/erp/apps')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.key', 'inventory')
            ->assertJsonPath('data.1.key', 'reporting')
            ->assertJsonPath('data.2.key', 'purchasing');
    }
}
