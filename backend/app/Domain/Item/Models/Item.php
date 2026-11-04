<?php

declare(strict_types=1);

namespace App\Domain\Item\Models;

use App\Domain\Category\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Item extends Model
{
    // Stok dan harga terakhir DIKECUALIKAN dari mass assignment.
    // Keduanya hanya boleh diubah oleh layanan mutasi stok/GRN pada fase berikutnya.
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'unit',
        'minimum_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'decimal:3',
            'minimum_stock' => 'decimal:3',
            'last_purchase_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_low_stock' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // MySQL DECIMAL dibandingkan di SQL, tidak dengan float PHP.
    public function scopeWithStockAlert(Builder $query): Builder
    {
        return $query->select('items.*')->selectRaw(
            'CASE WHEN items.stock_quantity <= items.minimum_stock THEN 1 ELSE 0 END AS is_low_stock'
        );
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('items.stock_quantity', '<=', 'items.minimum_stock');
    }
}
