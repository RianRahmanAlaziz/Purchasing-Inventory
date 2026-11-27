<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Models;

use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InventoryTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transfer_date' => 'date:Y-m-d', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'from_company_id')->withTrashed();
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InventoryTransferEvent::class)->orderBy('id');
    }
}
