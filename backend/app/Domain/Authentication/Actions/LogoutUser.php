<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Actions;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutUser
{
    public function execute(User $user): bool
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return (bool) $token->delete();
    }
}
