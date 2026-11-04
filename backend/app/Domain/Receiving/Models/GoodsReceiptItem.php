<?php

declare(strict_types=1);

namespace App\Domain\Receiving\Models;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'purchase_order_item_id',
        'qty_received', 'qty_damaged', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_received' => 'decimal:3',
            'qty_damaged' => 'decimal:3',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function movement(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'goods_receipt_item_id');
    }
}
