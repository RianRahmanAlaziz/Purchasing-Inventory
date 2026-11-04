<?php

declare(strict_types=1);

namespace App\Domain\Receiving\Models;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GoodsReceipt extends Model
{
    protected $fillable = [
        'grn_number', 'purchase_order_id', 'created_by', 'confirmed_by',
        'received_date', 'status', 'confirmed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
