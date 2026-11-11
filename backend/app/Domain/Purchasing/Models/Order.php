<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Order extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'status',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $query->whereHas('purchaseOrder', fn (Builder $po) => $po->visibleTo($actor));
    }

    /** PO soft-deleted tetap bisa dibaca untuk audit Order historis. */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }
}
