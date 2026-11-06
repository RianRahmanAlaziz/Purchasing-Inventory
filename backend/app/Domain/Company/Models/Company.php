<?php

declare(strict_types=1);

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Company extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'address', 'phone', 'email', 'logo'];
}
