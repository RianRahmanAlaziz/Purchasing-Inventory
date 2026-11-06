<?php

declare(strict_types=1);

namespace App\Domain\Document\Models;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Document extends Model
{
    use SoftDeletes;

    protected $fillable = ['document_number', 'document_type', 'supplier_id', 'purchase_order_id', 'document_date',
        'status', 'file_path', 'file_name', 'file_mime', 'file_size', 'notes', 'created_by', 'updated_by',
        'sent_at', 'sent_by', 'received_at', 'received_by', 'verified_at', 'verified_by'];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }
}
