<?php

declare(strict_types=1);

namespace App\Domain\Category\Models;

use Illuminate\Database\Eloquent\Model;

final class Category extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
