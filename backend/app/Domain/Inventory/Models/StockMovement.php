<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Company\Models\Company;
use App\Domain\Item\Models\Item;
use App\Domain\Transfer\Models\InventoryTransferItem;
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'from_company_id')->withTrashed();
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id')->withTrashed();
    }

    public function transferItem(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferItem::class, 'transfer_item_id');
    }
}
