<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Company\Models\Company;
use App\Domain\Item\Models\Item;
use App\Domain\Receiving\Models\GoodsReceiptItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class MaintenanceAsset extends Model
{
    protected $table = 'maintenance_assets';

    // PT, kode dan sumber registrasi hanya diisi Action internal.
    // Jangan menerima mass assignment langsung dari request.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'receipt_unit_ordinal' => 'integer',
            'registered_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function sourceGoodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class,  'source_goods_receipt_item_id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function vehicleProfile(): HasOne
    {
        return $this->hasOne(MaintenanceVehicleProfile::class,   'asset_id');
    }
}
