<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxRate extends Model
{
    protected $fillable = [
        'tax_type_id', 'label', 'rate_percent', 'taxable_base_factor',
        'effective_from', 'effective_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:4',
            'taxable_base_factor' => 'decimal:8',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }
}
