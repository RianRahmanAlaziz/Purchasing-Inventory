<?php

declare(strict_types=1);

namespace App\Domain\Distribution\Models;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Item\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class DistributionItem extends Model
{
    protected $fillable = ['distribution_id', 'item_id', 'quantity', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function movement(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'distribution_item_id');
    }
}
