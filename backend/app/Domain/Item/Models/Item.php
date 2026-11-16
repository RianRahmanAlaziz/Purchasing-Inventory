<?php

declare(strict_types=1);

namespace App\Domain\Item\Models;

use App\Domain\Category\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Item extends Model
{
    // Saldo stok dan harga pembelian terakhir diperbarui oleh layanan mutasi stok.
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'unit',
        'minimum_stock',
        'maximum_stock',
        'reference_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'decimal:3',
            'minimum_stock' => 'decimal:3',
            'maximum_stock' => 'decimal:3',
            'reference_price' => 'decimal:2',
            'last_purchase_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_low_stock' => 'boolean',
            'awaiting_first_receipt' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeInInventory(Builder $query): Builder
    {
        return $query->where('items.awaiting_first_receipt', false);
    }

    // MySQL DECIMAL dibandingkan di SQL, tidak dengan float PHP.
    public function scopeWithStockAlert(Builder $query): Builder
    {
        return $query->select('items.*')->selectRaw(
            'CASE WHEN items.stock_quantity <= items.minimum_stock THEN 1 ELSE 0 END AS is_low_stock'
        )->selectRaw("CASE
            WHEN items.stock_quantity <= items.minimum_stock THEN 'low_stock'
            WHEN items.stock_quantity > items.maximum_stock THEN 'overstock'
            ELSE 'normal_stock'
            END AS stock_level");
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('items.stock_quantity', '<=', 'items.minimum_stock');
    }
}
