<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /**
     * Response untuk request yang berhasil.
     */
    public static function success(
        mixed $data = null,
        string $message = 'Request successful',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }

    /**
     * Response untuk request yang gagal.
     */
    public static function error(
        string $message = 'Request failed',
        int $status = 400,
        mixed $errors = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
