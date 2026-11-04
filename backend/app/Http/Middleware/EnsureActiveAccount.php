<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveAccount
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active) {
            return ApiResponse::error(
                'Akun tidak aktif.',
                403
            );
        }

        $hasRole = $user->roles()
            ->where('guard_name', 'web')
            ->exists();

        $isEmployee = $user->employee()
            ->where('is_active', true)
            ->exists();

        if (! $hasRole && ! $isEmployee) {
            return ApiResponse::error(
                'Akun tidak memiliki akses.',
                403
            );
        }

        return $next($request);
    }
}
