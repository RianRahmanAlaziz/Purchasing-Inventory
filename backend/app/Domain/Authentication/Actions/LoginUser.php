<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class LoginUser
{
    /** @return array{user: User, token: NewAccessToken}|null */
    public function execute(string $email, string $password): ?array
    {
        return DB::transaction(function () use ($email, $password): ?array {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $user || ! $user->is_active || ! Hash::check($password, $user->password)) {
                return null;
            }

            $hasRole = $user->roles()->where('guard_name', 'web')->exists();
            $isEmployee = $user->employee()->where('is_active', true)->exists();

            if (! $hasRole && ! $isEmployee) {
                return null;
            }

            $token = $user->createToken(
                'purchasing-inventory-api',
                $hasRole ? ['*'] : ['employee:self'],
                now()->addHours(8)
            );

            return ['user' => $user, 'token' => $token];
        }, attempts: 3);
    }
}
