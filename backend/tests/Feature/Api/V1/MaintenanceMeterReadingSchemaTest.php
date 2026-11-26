<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Company\Models\Company;
use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Domain\Maintenance\Models\MaintenanceMeterReading;
use App\Domain\Maintenance\Models\MaintenanceMeterReadingEvent;
use App\Domain\Maintenance\Models\MaintenanceVehicleProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaintenanceMeterReadingSchemaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame(
            'purchasing_test',
            DB::selectOne('SELECT DATABASE() AS name')->name
        );
    }

    /** @return array{actor: User, asset: MaintenanceAsset, profile: MaintenanceVehicleProfile} */
    private function fixture(string $mode = 'both'): array
    {
        $actor = User::factory()->create(['is_active' => true]);
        $company = Company::query()->create([
            'name' => 'PT METER ' . Str::upper(Str::random(12)),
        ]);

        // Fixture langsung, bukan endpoint registrasi legacy untuk production.
        $asset = new MaintenanceAsset();
        $asset->company_id = $company->id;
        $asset->sequence_number = 1;
        $asset->asset_code = 'MTR-' . Str::upper(Str::random(14));
        $asset->asset_name = 'Unit Operasional Uji Meter';
        $asset->asset_type = 'heavy_equipment';
        $asset->registration_source = 'legacy_manual';
        $asset->registered_by = $actor->id;
        $asset->registered_at = now();
        $asset->is_active = true;
        $asset->save();

        $profile = new MaintenanceVehicleProfile();
        $profile->asset_id = $asset->id;
        $profile->meter_mode = $mode;
        $profile->created_by = $actor->id;
        $profile->save();

        return [
            'actor' => $actor,
            'asset' => $asset->refresh(),
            'profile' => $profile->refresh(),
        ];
    }

    private function reading(
        MaintenanceVehicleProfile $profile,
        User $actor,
        string $meterType = 'odometer_km',
        string $measuredAt = '2026-11-25 08:00:00',
        string $value = '12500.000'
    ): MaintenanceMeterReading {
        $reading = new MaintenanceMeterReading();
        $reading->vehicle_profile_id = $profile->id;
        $reading->meter_type = $meterType;
        $reading->reading_value = $value;
        $reading->measured_at = $measuredAt;
        $reading->source = 'manual';
        $reading->recorded_by = $actor->id;
        $reading->save();

        return $reading->refresh();
    }

    public function test_schema_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('maintenance_meter_readings'));
        $this->assertTrue(Schema::hasTable('maintenance_meter_reading_events'));

        $this->assertTrue(Schema::hasColumns('maintenance_meter_readings', [
            'id',
            'vehicle_profile_id',
            'meter_type',
            'reading_value',
            'measured_at',
            'source',
            'notes',
            'recorded_by',
            'corrected_by',
            'corrected_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('maintenance_meter_reading_events', [
            'id',
            'meter_reading_id',
            'changed_by',
            'action',
            'before_value',
            'after_value',
            'reason',
            'created_at',
        ]));
    }

    public function test_reading_relates_to_profile_asset_company_and_actor(): void
    {
        $f = $this->fixture();
        $reading = $this->reading($f['profile'], $f['actor']);

        $this->assertSame($f['profile']->id, $reading->vehicleProfile->id);
        $this->assertSame($f['asset']->id, $reading->vehicleProfile->asset->id);
        $this->assertSame($f['asset']->company_id, $reading->vehicleProfile->asset->company_id);
        $this->assertSame($f['actor']->id, $reading->recorder->id);
        $this->assertSame('12500.000', $reading->reading_value);
        $this->assertSame($reading->id, $f['profile']->fresh()->meterReadings->first()->id);
    }

    public function test_both_meter_types_may_share_the_same_measurement_time(): void
    {
        $f = $this->fixture('both');
        $km = $this->reading($f['profile'], $f['actor']);
        $hours = $this->reading(
            $f['profile'],
            $f['actor'],
            'hourmeter_hours',
            '2026-11-25 08:00:00',
            '1250.500'
        );

        $this->assertNotSame($km->id, $hours->id);
        $this->assertSame('1250.500', $hours->reading_value);
        $this->assertSame(2, $f['profile']->fresh()->meterReadings()->count());
    }

    public function test_duplicate_meter_type_and_time_on_one_profile_is_rejected(): void
    {
        $f = $this->fixture();
        $this->reading($f['profile'], $f['actor']);

        $this->expectException(QueryException::class);

        $this->reading(
            $f['profile'],
            $f['actor'],
            'odometer_km',
            '2026-11-25 08:00:00',
            '12501.000'
        );
    }

    public function test_same_meter_type_can_have_different_measurement_times(): void
    {
        $f = $this->fixture();
        $this->reading($f['profile'], $f['actor']);
        $this->reading(
            $f['profile'],
            $f['actor'],
            'odometer_km',
            '2026-11-26 08:00:00',
            '12600.000'
        );

        $this->assertSame(2, $f['profile']->meterReadings()->count());
    }

    public function test_same_time_can_be_used_by_different_vehicles(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $this->reading($first['profile'], $first['actor']);
        $this->reading($second['profile'], $second['actor']);

        $this->assertSame(1, $first['profile']->meterReadings()->count());
        $this->assertSame(1, $second['profile']->meterReadings()->count());
    }

    public function test_reading_requires_existing_vehicle_profile(): void
    {
        $f = $this->fixture();
        $ghost = new MaintenanceMeterReading();
        $ghost->vehicle_profile_id = 9999999999;
        $ghost->meter_type = 'odometer_km';
        $ghost->reading_value = '1.000';
        $ghost->measured_at = '2026-11-25 08:00:00';
        $ghost->recorded_by = $f['actor']->id;

        $this->expectException(QueryException::class);
        $ghost->save();
    }

    public function test_event_records_original_value_and_actor(): void
    {
        $f = $this->fixture();
        $reading = $this->reading($f['profile'], $f['actor']);
        $event = new MaintenanceMeterReadingEvent();
        $event->meter_reading_id = $reading->id;
        $event->changed_by = $f['actor']->id;
        $event->action = 'recorded';
        $event->before_value = null;
        $event->after_value = $reading->reading_value;
        $event->save();

        $this->assertSame($reading->id, $event->refresh()->reading->id);
        $this->assertSame($f['actor']->id, $event->actor->id);
        $this->assertNull($event->before_value);
        $this->assertSame('12500.000', $event->after_value);
        $this->assertSame(1, $reading->events()->count());
    }

    public function test_audited_reading_cannot_be_hard_deleted(): void
    {
        $f = $this->fixture();
        $reading = $this->reading($f['profile'], $f['actor']);

        DB::table('maintenance_meter_reading_events')->insert([
            'meter_reading_id' => $reading->id,
            'changed_by' => $f['actor']->id,
            'action' => 'recorded',
            'before_value' => null,
            'after_value' => $reading->reading_value,
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('maintenance_meter_readings')
            ->where('id', $reading->id)->delete();
    }

    public function test_reading_does_not_affect_inventory(): void
    {
        $f = $this->fixture();
        $beforeItems = DB::table('items')->count();
        $beforeMovements = DB::table('stock_movements')->count();

        $this->reading($f['profile'], $f['actor']);

        $this->assertSame($beforeItems, DB::table('items')->count());
        $this->assertSame($beforeMovements, DB::table('stock_movements')->count());
    }
}
