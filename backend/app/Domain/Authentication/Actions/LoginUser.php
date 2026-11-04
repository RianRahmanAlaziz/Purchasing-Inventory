<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class LoginUser
{
    /** @return array{user: User, token: NewAccessToken}|null */
    public function execute(
        string $email,
        string $password
    ): ?array {
        $user = User::query()
            ->where('email', $email)
            ->first();

        if (
            ! $user ||
            ! $user->is_active ||
            ! Hash::check($password, $user->password)
        ) {
            return null;
        }

        // Role dibaca dari database Spatie.
        // Tidak membatasi pada nama role tertentu.
        $hasRole = $user->roles()
            ->where('guard_name', 'web')
            ->exists();

        // Karyawan tanpa role dapat memakai self-service.
        $isEmployee = $user->employee()
            ->where('is_active', true)
            ->exists();

        if (! $hasRole && ! $isEmployee) {
            return null;
        }

        $abilities = $hasRole
            ? ['*']
            : ['employee:self'];

        $token = $user->createToken(
            'purchasing-inventory-api',
            $abilities,
            now()->addHours(8)
        );

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
