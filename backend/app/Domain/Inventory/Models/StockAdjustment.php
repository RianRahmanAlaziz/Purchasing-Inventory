<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class StockAdjustment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_fingerprint'];

    protected function casts(): array
    {
        return [
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'quantity_difference' => 'decimal:3',
            'applied_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function movement(): HasOne
    {
        return $this->hasOne(StockMovement::class);
    }
}
