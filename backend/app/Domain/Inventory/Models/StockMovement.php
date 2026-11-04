<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockMovement extends Model
{
    public $timestamps = false; // stock_movements Fase 02 hanya memiliki created_at

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'decimal:3',
            'balance_before' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
