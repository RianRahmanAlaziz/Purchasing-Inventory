<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\Services\ApplicationCatalog;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplicationController extends Controller
{
    public function __invoke(
        Request $request,
        ApplicationCatalog $catalog
    ): JsonResponse {
        return ApiResponse::success(
            data: $catalog->visibleTo($request->user()),
            message: 'Daftar aplikasi ERP berhasil diambil.',
        );
    }
}
