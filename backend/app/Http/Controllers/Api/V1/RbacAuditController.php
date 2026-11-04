<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class RbacAuditController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $logs = DB::table('rbac_audit_logs')->orderByDesc('id')->paginate(30);

        return ApiResponse::success(
            data: $logs->items(),
            message: 'Riwayat perubahan RBAC.',
            meta: [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ]
        );
    }
}
