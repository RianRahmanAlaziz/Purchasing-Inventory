<?php

declare(strict_types=1);

namespace App\Domain\Distribution\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DistributionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'distribution_id', 'actor_id', 'action', 'from_status',
        'to_status', 'notes', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
