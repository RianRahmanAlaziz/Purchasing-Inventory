<?php

declare(strict_types=1);

namespace App\Domain\Asset\Models;

use App\Domain\Company\Models\Company;
use App\Domain\Item\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OfficeAsset extends Model
{
    protected $guarded = ['id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class)->orderBy('id');
    }
}
