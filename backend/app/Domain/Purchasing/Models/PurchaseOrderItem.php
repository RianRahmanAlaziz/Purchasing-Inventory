<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Item\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'item_id', 'item_sku_snapshot',
        'item_name_snapshot', 'unit_snapshot', 'quantity',
        'unit_price', 'line_subtotal', 'notes',
        'discount_percent', 'discount_amount', 'tax_rate_id', 'tax_label_snapshot',
        'tax_direction_snapshot', 'tax_rate_percent_snapshot', 'tax_base_factor_snapshot',
        'taxable_base_amount', 'tax_amount',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_subtotal' => 'decimal:2',
            'discount_percent' => 'decimal:4', 'discount_amount' => 'decimal:2',
            'tax_rate_percent_snapshot' => 'decimal:4', 'tax_base_factor_snapshot' => 'decimal:8',
            'taxable_base_amount' => 'decimal:2', 'tax_amount' => 'decimal:2'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
