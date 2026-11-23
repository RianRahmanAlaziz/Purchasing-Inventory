<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Authorization\Services\ModulePermissionRegistrar;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModulePermissionRegistrarTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }

    private function permissionName(): string
    {
        return 'erp_test.module_'
            . strtolower((string) Str::ulid())
            . '.view';
    }

    public function test_registers_permissions_with_web_guard(): void
    {
        $name = $this->permissionName();

        app(ModulePermissionRegistrar::class)
            ->register([$name]);

        $this->assertDatabaseHas('permissions', [
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    public function test_registering_twice_does_not_duplicate(): void
    {
        $name = $this->permissionName();
        $registrar = app(ModulePermissionRegistrar::class);

        $registrar->register([$name, $name]);
        $registrar->register([$name]);

        $this->assertSame(
            1,
            Permission::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->count()
        );
    }

    public function test_registration_preserves_existing_role_access(): void
    {
        $role = Role::findOrCreate(
            'erp-test-role-' . Str::ulid(),
            'web'
        );

        $existing = Permission::findOrCreate(
            'reports.view',
            'web'
        );

        $role->givePermissionTo($existing);

        $newPermission = $this->permissionName();

        app(ModulePermissionRegistrar::class)
            ->register([$newPermission]);

        $this->assertTrue(
            $role->fresh()->hasPermissionTo('reports.view')
        );

        $this->assertFalse(
            $role->fresh()->hasPermissionTo($newPermission)
        );
    }

    public function test_invalid_permission_does_not_register_anything(): void
    {
        $valid = $this->permissionName();
        $exceptionThrown = false;

        try {
            app(ModulePermissionRegistrar::class)
                ->register([
                    $valid,
                    'Maintenance.Invalid',
                ]);
        } catch (InvalidArgumentException) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);

        $this->assertDatabaseMissing('permissions', [
            'name' => $valid,
            'guard_name' => 'web',
        ]);
    }
}
