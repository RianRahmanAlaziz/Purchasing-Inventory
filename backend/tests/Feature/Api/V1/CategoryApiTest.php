<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CategoryApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name,
            'Tes ini hanya boleh memakai MySQL purchasing_test.',
        );

        Permission::findOrCreate('categories.view', 'web');
        Permission::findOrCreate('categories.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actingWith(array $permissions): void
    {
        $role = Role::findOrCreate('category_feature_tester', 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
    }

    private function category(string $code, string $name, bool $active = true): Category
    {
        return Category::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    public function test_guest_cannot_read_categories(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }

    public function test_viewer_can_read_but_cannot_write(): void
    {
        $this->actingWith(['categories.view']);
        $category = $this->category('IT', 'Information Technology');

        $this->getJson('/api/v1/categories/'.$category->id)
            ->assertOk()->assertJsonPath('data.code', 'IT');

        $this->postJson('/api/v1/categories', [
            'code' => 'HR',
            'name' => 'Human Resources',
        ])->assertForbidden();

        $this->deleteJson('/api/v1/categories/'.$category->id)
            ->assertForbidden();
    }

    public function test_manager_can_create_category_with_normalized_fields(): void
    {
        $this->actingWith(['categories.manage']);

        $this->postJson('/api/v1/categories', [
            'code' => '  atk-001 ',
            'name' => '  Alat    Tulis Kantor  ',
            'description' => 'Perlengkapan kantor',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'ATK-001')
            ->assertJsonPath('data.name', 'Alat Tulis Kantor')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', [
            'code' => 'ATK-001',
            'name' => 'Alat Tulis Kantor',
        ]);
    }

    public function test_duplicate_category_code_returns_422(): void
    {
        $this->actingWith(['categories.manage']);
        $this->category('ATK', 'Alat Tulis Kantor');

        $this->postJson('/api/v1/categories', [
            'code' => 'atk',
            'name' => 'Peralatan Lain',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_duplicate_category_name_returns_422(): void
    {
        $this->actingWith(['categories.manage']);
        $this->category('ATK', 'Alat Tulis Kantor');

        $this->postJson('/api/v1/categories', [
            'code' => 'ATK2',
            'name' => 'Alat Tulis Kantor',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_invalid_code_returns_422(): void
    {
        $this->actingWith(['categories.manage']);

        $this->postJson('/api/v1/categories', [
            'code' => 'TIDAK VALID!',
            'name' => 'Kategori Invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_update_category_and_deactivate(): void
    {
        $this->actingWith(['categories.manage']);
        $category = $this->category('ELEC', 'Elektronik');

        $this->patchJson('/api/v1/categories/'.$category->id, [
            'name' => ' Elektronik   Kantor ',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Elektronik Kantor')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_update_cannot_use_existing_code(): void
    {
        $this->actingWith(['categories.manage']);
        $this->category('ATK', 'Alat Tulis Kantor');
        $category = $this->category('ELEC', 'Elektronik');

        $this->patchJson('/api/v1/categories/'.$category->id, [
            'code' => 'atk',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_list_supports_search_active_filter_and_pagination(): void
    {
        $this->actingWith(['categories.view']);
        $this->category('ATK', 'Alat Tulis Kantor');
        $this->category('ATKOLD', 'Alat Tulis Lama', false);
        $this->category('ELEC', 'Elektronik');

        $this->getJson('/api/v1/categories?search=Alat&is_active=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ATK')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_unused_category_can_be_deleted(): void
    {
        $this->actingWith(['categories.manage']);
        $category = $this->category('TEMP', 'Sementara');

        $this->deleteJson('/api/v1/categories/'.$category->id)->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_referenced_by_item_cannot_be_deleted(): void
    {
        $this->actingWith(['categories.manage']);
        $category = $this->category('ATK', 'Alat Tulis Kantor');

        DB::table('items')->insert([
            'category_id' => $category->id,
            'sku' => 'SKU-CAT-001',
            'name' => 'Pulpen',
            'unit' => 'pcs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson('/api/v1/categories/'.$category->id)->assertStatus(409);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_unknown_category_returns_404(): void
    {
        $this->actingWith(['categories.view']);
        $this->getJson('/api/v1/categories/999999999')->assertNotFound();
    }

    public function test_manager_can_create_inactive_category(): void
    {
        $this->actingWith(['categories.manage']);

        $this->postJson('/api/v1/categories', [
            'code' => 'ARSIP',
            'name' => 'Kategori Arsip',
            'is_active' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('categories', [
            'code' => 'ARSIP',
            'is_active' => false,
        ]);
    }
}
