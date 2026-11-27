<?php

declare(strict_types=1);

namespace App\Domain\Item\Models;

use App\Domain\Category\Models\Category;
use App\Domain\Inventory\Models\InventoryBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Item extends Model
{
    // Saldo stok dan harga pembelian terakhir diperbarui oleh layanan mutasi stok.
    protected $fillable = [
        'category_id',
        'item_type',
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

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public static function stockExpression(?int $companyId = null): string
    {
        return $companyId === null ? 'items.stock_quantity'
            : '(SELECT COALESCE(SUM(quantity), 0) FROM inventory_balances WHERE item_id = items.id AND company_id = '.(int) $companyId.')';
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
    public function scopeWithStockAlert(Builder $query, ?int $companyId = null): Builder
    {
        $stock = self::stockExpression($companyId);

        return $query->select('items.*')->selectRaw($stock.' AS company_stock_quantity')->selectRaw(
            'CASE WHEN '.$stock.' <= items.minimum_stock THEN 1 ELSE 0 END AS is_low_stock'
        )->selectRaw("CASE WHEN {$stock} <= items.minimum_stock THEN 'low_stock'
            WHEN {$stock} > items.maximum_stock THEN 'overstock' ELSE 'normal_stock' END AS stock_level");
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('items.stock_quantity', '<=', 'items.minimum_stock');
    }
}
