<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Models;

use Illuminate\Database\Eloquent\Model;

final class Supplier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'contact_person',
        'phone',
        'email',
        'address',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
