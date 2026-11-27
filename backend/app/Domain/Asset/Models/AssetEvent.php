<?php

declare(strict_types=1);

namespace App\Domain\Asset\Models;

use App\Domain\Company\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_fingerprint'];

    protected $appends = ['reason'];

    protected function casts(): array
    {
        return ['reference' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'from_company_id')->withTrashed();
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id')->withTrashed();
    }

    public function getReasonAttribute(): ?string
    {
        return $this->reference['reason'] ?? null;
    }
}
