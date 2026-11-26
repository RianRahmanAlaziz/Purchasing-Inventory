<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MaintenanceMeterReading extends Model
{
    public const TYPE_ODOMETER_KM = 'odometer_km';
    public const TYPE_HOURMETER_HOURS = 'hourmeter_hours';

    public const METER_TYPES = [
        self::TYPE_ODOMETER_KM,
        self::TYPE_HOURMETER_HOURS,
    ];

    protected $table = 'maintenance_meter_readings';

    // Request tidak boleh mass-assign histori meter maupun nilai koreksi.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'reading_value' => 'decimal:3',
            'measured_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    public function vehicleProfile(): BelongsTo
    {
        return $this->belongsTo(
            MaintenanceVehicleProfile::class,
            'vehicle_profile_id'
        );
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            MaintenanceMeterReadingEvent::class,
            'meter_reading_id'
        )->orderBy('id');
    }
}
