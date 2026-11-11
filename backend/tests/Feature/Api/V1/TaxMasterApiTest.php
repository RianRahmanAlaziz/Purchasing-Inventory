<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxType;
use App\Models\User;
use Database\Seeders\PpnIndonesia2026Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TaxMasterApiTest extends TestCase
{
    use DatabaseTransactions;

    private const REFNUSA = 'https://refnusa.dev/v1/tax-rates?category=ppn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        Permission::findOrCreate('taxes.view', 'web');
        Permission::findOrCreate('taxes.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actingWith(array $permissions): User
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function type(string $code = 'PPN', bool $active = true): TaxType
    {
        return TaxType::query()->create([
            'code' => $code,
            'name' => 'Jenis '.$code,
            'direction' => 'addition',
            'is_active' => $active,
        ]);
    }

    private function rate(TaxType $type, array $data = []): TaxRate
    {
        return TaxRate::query()->create(array_merge([
            'tax_type_id' => $type->id,
            'label' => 'Tarif contoh',
            'rate_percent' => '11.0000',
            'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'is_active' => true,
        ], $data));
    }

    private function ratePayload(TaxType $type, array $data = []): array
    {
        return array_merge([
            'tax_type_id' => $type->id,
            'label' => 'Tarif 11 persen',
            'rate_percent' => '11.0000',
            'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-12-31',
        ], $data);
    }

    public function test_guest_cannot_read_tax_master(): void
    {
        $this->getJson('/api/v1/tax-types')->assertUnauthorized();
        $this->getJson('/api/v1/tax-rates')->assertUnauthorized();
        $this->getJson('/api/v1/taxes/options')->assertUnauthorized();
        $this->postJson('/api/v1/tax-rates/import/refnusa')->assertUnauthorized();
    }

    public function test_role_without_permission_cannot_access_tax_master(): void
    {
        $this->actingWith([]);
        $this->getJson('/api/v1/tax-types')->assertForbidden();
        $this->postJson('/api/v1/tax-types', [])->assertForbidden();
    }

    public function test_viewer_can_read_but_cannot_change_tax_master(): void
    {
        $this->actingWith(['taxes.view']);
        $type = $this->type();
        $rate = $this->rate($type);
        $this->getJson('/api/v1/tax-types/'.$type->id)->assertOk()
            ->assertJsonPath('data.code', 'PPN');
        $this->getJson('/api/v1/tax-rates/'.$rate->id)->assertOk()
            ->assertJsonPath('data.rate_percent', '11.0000');
        $this->postJson('/api/v1/tax-types', [])->assertForbidden();
        $this->deleteJson('/api/v1/tax-rates/'.$rate->id)->assertForbidden();
        Http::preventStrayRequests()->fake();
        $this->postJson('/api/v1/tax-rates/import/refnusa')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_imported_ppn_is_inactive_until_reviewed_and_can_be_reimported(): void
    {
        $this->actingWith(['taxes.view', 'taxes.manage']);
        Http::preventStrayRequests()->fake([
            self::REFNUSA => Http::response(['rates' => $this->refnusaPpnRates()]),
        ]);

        $this->postJson('/api/v1/tax-rates/import/refnusa')->assertOk()
            ->assertJsonPath('data.imported', 1)->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseMissing('tax_types', ['code' => 'PPN_MEWAH']);
        $reduced = TaxType::query()->where('code', 'PPN_NON_MEWAH')->firstOrFail();
        $reducedRate = $reduced->rates()->sole();
        $this->assertFalse($reduced->is_active);
        $this->assertFalse($reducedRate->is_active);
        $this->assertSame('12.0000', $reducedRate->rate_percent);
        $this->assertSame('0.91666667', $reducedRate->taxable_base_factor);
        $this->assertSame('2025-01-01', $reducedRate->effective_from->format('Y-m-d'));
        $this->getJson('/api/v1/taxes/options?date=2025-01-01')->assertOk()
            ->assertJsonMissing(['label' => $reducedRate->label]);

        $this->patchJson('/api/v1/tax-types/'.$reduced->id, ['is_active' => true])->assertOk();
        $this->patchJson('/api/v1/tax-rates/'.$reducedRate->id, [
            'is_active' => true, 'label' => 'PPN sudah ditinjau',
        ])->assertOk();
        $this->postJson('/api/v1/tax-rates/import/refnusa')->assertOk()
            ->assertJsonPath('data.imported', 0)->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, $reduced->rates()->count());
        $this->getJson('/api/v1/taxes/options?date=2025-01-01')->assertOk()
            ->assertJsonFragment(['label' => 'PPN sudah ditinjau', 'is_active' => true]);
    }

    public function test_import_rejects_unavailable_or_incomplete_source_without_changes(): void
    {
        $this->actingWith(['taxes.manage']);
        Http::preventStrayRequests()->fake([
            self::REFNUSA => Http::sequence()->pushStatus(429)->push(['rates' => [$this->refnusaPpnRates()[0]]]),
        ]);

        $this->postJson('/api/v1/tax-rates/import/refnusa')->assertStatus(502);
        $this->postJson('/api/v1/tax-rates/import/refnusa')->assertStatus(502);
        $this->assertDatabaseMissing('tax_types', ['code' => 'PPN_MEWAH']);
        $this->assertDatabaseMissing('tax_types', ['code' => 'PPN_NON_MEWAH']);
    }

    public function test_import_rolls_back_if_a_type_code_is_used_for_withholding(): void
    {
        $this->actingWith(['taxes.manage']);
        $conflict = $this->type('PPN_NON_MEWAH');
        $conflict->update(['direction' => 'withholding']);
        Http::preventStrayRequests()->fake([
            self::REFNUSA => Http::response(['rates' => $this->refnusaPpnRates()]),
        ]);

        $this->postJson('/api/v1/tax-rates/import/refnusa')->assertConflict();
        $this->assertDatabaseMissing('tax_types', ['code' => 'PPN_MEWAH']);
        $this->assertSame('withholding', $conflict->refresh()->direction);
        $this->assertSame(0, $conflict->rates()->count());
    }

    public function test_2026_ppn_seed_enables_only_office_ppn_and_preserves_edits(): void
    {
        $this->actingWith(['taxes.view']);

        $this->seed(PpnIndonesia2026Seeder::class);

        $this->assertDatabaseCount('tax_types', 1);
        $this->assertDatabaseCount('tax_rates', 1);
        $this->getJson('/api/v1/taxes/options?date=2026-11-11')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tax_type.code', 'PPN_NON_MEWAH')
            ->assertJsonPath('data.0.rate_percent', '12.0000')
            ->assertJsonPath('data.0.taxable_base_factor', '0.91666667');

        $existing = TaxRate::query()->sole();
        $existing->update(['label' => 'Tarif PPN yang sudah diperiksa', 'is_active' => false]);
        $this->seed(PpnIndonesia2026Seeder::class);

        $this->assertDatabaseCount('tax_types', 1);
        $this->assertDatabaseCount('tax_rates', 1);
        $this->assertDatabaseHas('tax_rates', [
            'id' => $existing->id,
            'label' => 'Tarif PPN yang sudah diperiksa',
            'is_active' => false,
        ]);
    }

    private function refnusaPpnRates(): array
    {
        $reference = [
            'category' => 'ppn',
            'unit' => 'percent',
            'effective_date' => '2025-01-01',
            'source_url' => 'https://jdih.kemenkeu.go.id/api/download/ad276b82-94bd-4197-b409-af33e2842cd6/2024pmkeuangan131.pdf',
        ];

        return [
            $reference + ['rate_code' => 'ppn_rate', 'value' => 12],
            $reference + ['rate_code' => 'ppn_non_mewah_effective', 'value' => 11],
        ];
    }

    public function test_manager_can_create_normalized_active_tax_type(): void
    {
        $this->actingWith(['taxes.manage']);
        $this->postJson('/api/v1/tax-types', [
            'code' => ' pph_23 ',
            'name' => '  Pajak   Penghasilan Pasal 23 ',
            'direction' => 'withholding',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'PPH_23')
            ->assertJsonPath('data.name', 'Pajak Penghasilan Pasal 23')
            ->assertJsonPath('data.direction', 'withholding')
            ->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('tax_types', ['code' => 'PPH_23']);
    }

    public function test_duplicate_type_code_returns_422(): void
    {
        $this->actingWith(['taxes.manage']);
        $this->type();
        $this->postJson('/api/v1/tax-types', [
            'code' => 'ppn', 'name' => 'Duplikat', 'direction' => 'addition',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_invalid_type_direction_returns_422(): void
    {
        $this->actingWith(['taxes.manage']);
        $this->postJson('/api/v1/tax-types', [
            'code' => 'BAD', 'name' => 'Salah', 'direction' => 'credit',
        ])->assertUnprocessable()->assertJsonValidationErrors('direction');
    }

    public function test_type_direction_can_change_before_any_rate_exists(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $this->patchJson('/api/v1/tax-types/'.$type->id, [
            'direction' => 'withholding',
        ])->assertOk()->assertJsonPath('data.direction', 'withholding');
    }

    public function test_type_direction_cannot_change_when_rates_exist(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $this->rate($type);
        $this->patchJson('/api/v1/tax-types/'.$type->id, [
            'direction' => 'withholding',
        ])->assertStatus(409);
        $this->assertDatabaseHas('tax_types', ['id' => $type->id, 'direction' => 'addition']);
    }

    public function test_manager_can_create_rate_with_exact_decimal_strings(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $this->postJson('/api/v1/tax-rates', $this->ratePayload($type, [
            'rate_percent' => '11.5000',
            'taxable_base_factor' => '0.91666667',
        ]))->assertCreated()
            ->assertJsonPath('data.rate_percent', '11.5000')
            ->assertJsonPath('data.taxable_base_factor', '0.91666667')
            ->assertJsonPath('data.tax_type.direction', 'addition');
    }

    public function test_rate_can_be_created_without_dates_and_defaults_to_local_today(): void
    {
        $this->actingWith(['taxes.manage']);
        $this->travelTo(now('UTC')->setDate(2026, 11, 10)->setTime(17, 30));
        $type = $this->type();
        $payload = $this->ratePayload($type);
        unset($payload['taxable_base_factor'], $payload['effective_from'], $payload['effective_until']);
        $this->postJson('/api/v1/tax-rates', $payload)->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.taxable_base_factor', '1.00000000')
            ->assertJsonPath('data.effective_from', '2026-11-11')
            ->assertJsonPath('data.effective_until', null);
    }

    public function test_overlapping_active_rate_is_rejected_with_409(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $this->rate($type, ['effective_from' => '2026-01-01', 'effective_until' => '2026-12-31']);
        $this->postJson('/api/v1/tax-rates', $this->ratePayload($type, [
            'effective_from' => '2026-06-01', 'effective_until' => '2027-06-01',
        ]))->assertStatus(409);
        $this->assertSame(1, TaxRate::query()->count());
    }

    public function test_non_overlapping_adjacent_periods_are_allowed(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $this->rate($type, ['effective_from' => '2026-01-01', 'effective_until' => '2026-06-30']);
        $this->postJson('/api/v1/tax-rates', $this->ratePayload($type, [
            'effective_from' => '2026-07-01', 'effective_until' => null,
        ]))->assertCreated();
        $this->assertSame(2, TaxRate::query()->count());
    }

    public function test_end_date_before_start_returns_422(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $this->postJson('/api/v1/tax-rates', $this->ratePayload($type, [
            'effective_from' => '2026-07-01', 'effective_until' => '2026-06-30',
        ]))->assertUnprocessable()->assertJsonValidationErrors('effective_until');
    }

    public function test_updating_rate_to_overlap_existing_range_is_rejected(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $first = $this->rate($type, ['effective_from' => '2026-01-01', 'effective_until' => '2026-06-30']);
        $second = $this->rate($type, ['effective_from' => '2026-07-01', 'effective_until' => null]);
        $this->patchJson('/api/v1/tax-rates/'.$second->id, [
            'effective_from' => '2026-06-15',
        ])->assertStatus(409);
        $this->assertSame('2026-07-01', $second->fresh()->effective_from->format('Y-m-d'));
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_active_rate_cannot_be_created_for_inactive_type(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type('PPH', false);
        $this->postJson('/api/v1/tax-rates', $this->ratePayload($type))
            ->assertStatus(409);
        $this->postJson('/api/v1/tax-rates', $this->ratePayload($type, [
            'is_active' => false,
        ]))->assertCreated()->assertJsonPath('data.is_active', false);
    }

    public function test_options_only_return_effective_active_rates_and_types(): void
    {
        $this->actingWith(['taxes.view']);
        $current = $this->type('CURRENT');
        $this->rate($current, ['effective_from' => '2026-01-01', 'effective_until' => '2026-12-31']);
        $inactiveType = $this->type('OLDTYPE', false);
        $this->rate($inactiveType);
        $future = $this->type('FUTURE');
        $this->rate($future, ['effective_from' => '2027-01-01']);
        $inactiveRateType = $this->type('INACTIVE');
        $this->rate($inactiveRateType, ['is_active' => false]);

        $this->getJson('/api/v1/taxes/options?date=2026-11-04')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tax_type.code', 'CURRENT')
            ->assertJsonPath('meta.date', '2026-11-04');
    }

    public function test_type_with_rates_cannot_be_deleted(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $this->rate($type);
        $this->deleteJson('/api/v1/tax-types/'.$type->id)->assertStatus(409);
        $this->assertDatabaseHas('tax_types', ['id' => $type->id]);
    }

    public function test_unused_type_and_rate_can_be_deleted(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $rate = $this->rate($type);
        $this->deleteJson('/api/v1/tax-rates/'.$rate->id)->assertOk();
        $this->deleteJson('/api/v1/tax-types/'.$type->id)->assertOk();
        $this->assertDatabaseMissing('tax_types', ['id' => $type->id]);
        $this->assertDatabaseMissing('tax_rates', ['id' => $rate->id]);
    }

    public function test_rate_tax_type_cannot_be_moved_in_update(): void
    {
        $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $rate = $this->rate($type);
        $another = $this->type('PPH');
        $this->patchJson('/api/v1/tax-rates/'.$rate->id, [
            'tax_type_id' => $another->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_type_id');
    }

    public function test_rate_referenced_by_po_cannot_be_changed_or_deleted(): void
    {
        $user = $this->actingWith(['taxes.manage']);
        $type = $this->type();
        $rate = $this->rate($type);
        $now = now();

        $departmentId = DB::table('departments')->insertGetId([
            'code' => 'TXD', 'name' => 'Dept Pajak Uji', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'department_id' => $departmentId, 'employee_number' => 'TXEMP001',
            'name' => 'Karyawan Pajak Uji', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'TXSUP', 'name' => 'Distributor Pajak Uji',
            'normalized_name' => 'distributor pajak uji', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'TX-PO-01', 'supplier_id' => $supplierId,
            'requested_for_employee_id' => $employeeId,
            'requested_for_department_id' => $departmentId,
            'created_by' => $user->id,
            'supplier_name_snapshot' => 'Distributor Pajak Uji',
            'supplier_code_snapshot' => 'TXSUP',
            'order_date' => '2026-11-04', 'status' => 'draft',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('purchase_order_taxes')->insert([
            'purchase_order_id' => $poId,
            'tax_rate_id' => $rate->id,
            'tax_code_snapshot' => $type->code,
            'tax_name_snapshot' => $type->name,
            'direction_snapshot' => $type->direction,
            'rate_percent_snapshot' => '11.0000',
            'base_factor_snapshot' => '1.00000000',
            'taxable_base_amount' => '100000.00',
            'tax_amount' => '11000.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->patchJson('/api/v1/tax-rates/'.$rate->id, [
            'rate_percent' => '12.0000',
        ])->assertStatus(409);
        $this->deleteJson('/api/v1/tax-rates/'.$rate->id)->assertStatus(409);
        $this->patchJson('/api/v1/tax-rates/'.$rate->id, [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('purchase_order_taxes', [
            'purchase_order_id' => $poId,
            'tax_rate_id' => $rate->id,
            'rate_percent_snapshot' => '11.0000',
        ]);
    }

    public function test_types_list_supports_filter_and_pagination(): void
    {
        $this->actingWith(['taxes.view']);
        $this->type('PPN');
        $this->type('PPH', false);
        $this->getJson('/api/v1/tax-types?search=PP&is_active=1&per_page=10')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'PPN')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_unknown_tax_type_and_rate_return_404(): void
    {
        $this->actingWith(['taxes.view']);
        $this->getJson('/api/v1/tax-types/999999')->assertNotFound();
        $this->getJson('/api/v1/tax-rates/999999')->assertNotFound();
    }
}
