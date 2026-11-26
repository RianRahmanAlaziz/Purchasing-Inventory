<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MaintenanceVehicleProfile extends Model
{
    public const METER_ODOMETER_KM = 'odometer_km';

    public const METER_HOURMETER_HOURS = 'hourmeter_hours';

    public const METER_BOTH = 'both';

    public const METER_MODES = [
        self::METER_ODOMETER_KM,
        self::METER_HOURMETER_HOURS,
        self::METER_BOTH,
    ];

    protected $table = 'maintenance_vehicle_profiles';

    // Pembuatan dan perubahan hanya melalui Action khusus.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'manufacture_year' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(
            MaintenanceAsset::class,
            'asset_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    public function usesOdometer(): bool
    {
        return in_array(
            $this->meter_mode,
            [
                self::METER_ODOMETER_KM,
                self::METER_BOTH,
            ],
            true
        );
    }

    public function usesHourmeter(): bool
    {
        return in_array(
            $this->meter_mode,
            [
                self::METER_HOURMETER_HOURS,
                self::METER_BOTH,
            ],
            true
        );
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(
            MaintenanceMeterReading::class,
            'vehicle_profile_id'
        )->orderByDesc('measured_at')->orderByDesc('id');
    }
}
