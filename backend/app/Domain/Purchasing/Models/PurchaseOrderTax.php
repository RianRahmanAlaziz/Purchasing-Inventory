<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Tax\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderTax extends Model
{
    protected $fillable = [
        'purchase_order_id', 'tax_rate_id', 'tax_code_snapshot',
        'tax_name_snapshot', 'direction_snapshot',
        'rate_percent_snapshot', 'base_factor_snapshot',
        'taxable_base_amount', 'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent_snapshot' => 'decimal:4',
            'base_factor_snapshot' => 'decimal:8',
            'taxable_base_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }
}
