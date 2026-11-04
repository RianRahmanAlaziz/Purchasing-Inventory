<?php

declare(strict_types=1);

namespace App\Domain\Distribution\Models;

use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Distribution extends Model
{
    protected $fillable = [
        'distribution_number', 'employee_id', 'requested_by', 'approved_by',
        'sent_by', 'received_by', 'status', 'purpose', 'requested_at',
        'approved_at', 'sent_at', 'received_at', 'cancelled_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'approved_at' => 'datetime',
            'sent_at' => 'datetime', 'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DistributionItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DistributionEvent::class)->orderBy('id');
    }
}
