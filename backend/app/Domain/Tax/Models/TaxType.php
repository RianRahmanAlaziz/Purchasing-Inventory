<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TaxType extends Model
{
    protected $fillable = ['code', 'name', 'direction', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
