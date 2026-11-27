<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryTransferEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
