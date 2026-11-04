<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'service' => 'purchasing-inventory',
                'version' => 'v1',
                'status' => 'ok',
            ],
            message: 'API is running.'
        );
    }
}
