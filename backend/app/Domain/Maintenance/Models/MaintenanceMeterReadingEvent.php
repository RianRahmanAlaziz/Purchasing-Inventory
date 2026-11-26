<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MaintenanceMeterReadingEvent extends Model
{
    public $timestamps = false;

    protected $table = 'maintenance_meter_reading_events';
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'before_value' => 'decimal:3',
            'after_value' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(
            MaintenanceMeterReading::class,
            'meter_reading_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
