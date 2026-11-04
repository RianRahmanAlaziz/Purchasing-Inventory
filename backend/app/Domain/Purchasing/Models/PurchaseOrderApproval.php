<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderApproval extends Model
{
    protected $fillable = [
        'purchase_order_id', 'step_order', 'role_name',
        'status', 'decided_by', 'decided_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
