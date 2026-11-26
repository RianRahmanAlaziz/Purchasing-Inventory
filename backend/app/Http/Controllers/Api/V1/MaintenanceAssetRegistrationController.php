<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Maintenance\Actions\RegisterAssetsFromConfirmedGrn;
use App\Domain\Maintenance\Exceptions\AssetRegistrationConflict;
use App\Domain\Maintenance\Queries\ListAssetCandidates;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Maintenance\RegisterAssetsFromGrnRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MaintenanceAssetRegistrationController extends Controller
{
    public function candidates(
        Request $request,
        ListAssetCandidates $query
    ): JsonResponse {
        abort_unless(
            $request->user()->can(
                'maintenance.assets.candidates.view'
            )
                && $request->user()->can(
                    'maintenance.assets.view-all-companies'
                ),
            403
        );

        $filters = $request->validate([
            'company_id' => [
                'sometimes',
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'between:1,100',
            ],
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ]);

        $page = $query->paginate(
            isset($filters['company_id'])
                ? (int) $filters['company_id']
                : null,
            (int) ($filters['per_page'] ?? 15)
        );

        return ApiResponse::success(
            data: $page->items(),
            message: 'Calon aset dari GRN confirmed berhasil diambil.',
            meta: [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]
        );
    }

    public function store(
        RegisterAssetsFromGrnRequest $request,
        RegisterAssetsFromConfirmedGrn $action
    ): JsonResponse {
        try {
            $result = $action->execute(
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success(
                data: $result['data'],
                message: $result['replayed']
                    ? 'Registrasi lama dikembalikan tanpa membuat aset baru.'
                    : 'Registrasi aset berhasil.',
                status: $result['replayed'] ? 200 : 201
            );
        } catch (AssetRegistrationConflict $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                409
            );
        }
    }
}
