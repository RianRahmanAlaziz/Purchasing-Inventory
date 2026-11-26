<?php

declare(strict_types=1);

namespace App\Domain\Item\Policies;

use App\Models\User;

final class ItemPolicy
{
    public static function canCreate(?User $user): bool
    {
        return $user !== null && (
            ($user->hasRole('super_admin', 'web') && $user->can('items.manage'))
            || (
                ($user->hasRole('staff_hr', 'web') || $user->hasRole('staff_hr_ga', 'web'))
                && $user->can('items.create')
            )
        );
    }
}
