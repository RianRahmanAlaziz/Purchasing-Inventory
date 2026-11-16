<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderCancellation extends Model
{
    protected $fillable = ['requested_by', 'status', 'reason', 'decided_by', 'decided_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
