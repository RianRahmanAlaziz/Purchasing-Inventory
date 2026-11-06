<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = ['invoice_number', 'supplier_id', 'purchase_order_id', 'invoice_date', 'due_date', 'total_amount', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'total_amount' => 'decimal:2'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeWithBalances(Builder $query): void
    {
        $query->with(['supplier', 'purchaseOrder'])
            ->withCount(['payments as payment_history_count' => fn ($q) => $q->withTrashed()])
            ->withSum(['payments as verified_amount' => fn ($q) => $q->where('status', 'verified')], 'amount')
            ->withSum(['payments as pending_amount' => fn ($q) => $q->where('status', 'pending_verification')], 'amount');
    }
}
