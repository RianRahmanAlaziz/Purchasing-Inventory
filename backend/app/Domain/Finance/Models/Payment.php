<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = ['payment_number', 'invoice_id', 'payment_date', 'amount', 'reference', 'notes', 'status', 'created_by', 'verified_by', 'verified_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
