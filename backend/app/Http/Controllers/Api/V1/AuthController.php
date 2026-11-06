<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authentication\Actions\LoginUser;
use App\Domain\Authentication\Actions\LogoutUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authentication\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        LoginUser $action
    ): JsonResponse {
        $result = $action->execute(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        if ($result === null) {
            return ApiResponse::error(
                'Email atau password salah, atau akun tidak aktif.',
                401
            )->header('Cache-Control', 'no-store');
        }

        return ApiResponse::success(
            data: [
                'token' => $result['token']->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $result['token']
                    ->accessToken
                    ->expires_at?->toIso8601String(),
                'user' => $this->sessionUser($result['user'], $request),
            ],
            message: 'Login berhasil.',
        )->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'user' => $this->sessionUser($request->user(), $request),
            ],
            message: 'Profil berhasil diambil.',
        )->header('Cache-Control', 'no-store');
    }

    public function logout(
        Request $request,
        LogoutUser $action
    ): JsonResponse {
        if (! $action->execute($request->user())) {
            return ApiResponse::error(
                'Logout membutuhkan Bearer token yang valid.',
                403
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'Logout berhasil.',
        )->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function sessionUser(User $user, Request $request): array
    {
        return [
            ...(new UserResource($user))->toArray($request),
            'default_company_id' => $user->default_company_id,
            'permissions' => $user->getAllPermissions()->where('guard_name', 'web')
                ->pluck('name')->unique()->sort()->values()->all(),
        ];
    }
}
