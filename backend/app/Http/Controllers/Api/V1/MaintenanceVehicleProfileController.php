<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Maintenance\Actions\SaveMaintenanceVehicleProfile;
use App\Domain\Maintenance\Actions\VehicleProfileConflict;
use App\Domain\Maintenance\Models\MaintenanceVehicleProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Maintenance\SaveMaintenanceVehicleProfileRequest;
use App\Http\Resources\Api\V1\MaintenanceVehicleProfileResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MaintenanceVehicleProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            MaintenanceVehicleProfile::class
        );

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],

            'company_id' => [
                'sometimes',
                'integer',
                Rule::exists('companies', 'id'),
            ],

            'asset_type' => [
                'sometimes',
                Rule::in(['vehicle', 'heavy_equipment']),
            ],

            'meter_mode' => [
                'sometimes',
                Rule::in(
                    MaintenanceVehicleProfile::METER_MODES
                ),
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

        $query = MaintenanceVehicleProfile::query()
            ->with([
                'asset.company',
                'creator:id,name',
                'updater:id,name',
            ]);

        if (
            isset($filters['search'])
            && trim($filters['search']) !== ''
        ) {
            $search = trim($filters['search']);

            $query->where(
                static function (Builder $builder) use ($search): void {
                    $builder
                        ->where(
                            'fleet_number',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'registration_number',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'brand',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'asset',
                            static fn(Builder $assetQuery) =>
                            $assetQuery->where(
                                'asset_code',
                                'like',
                                "%{$search}%"
                            )
                        );
                }
            );
        }

        if (isset($filters['company_id'])) {
            $query->whereHas(
                'asset',
                static fn(Builder $assetQuery) =>
                $assetQuery->where(
                    'company_id',
                    $filters['company_id']
                )
            );
        }

        if (isset($filters['asset_type'])) {
            $query->whereHas(
                'asset',
                static fn(Builder $assetQuery) =>
                $assetQuery->where(
                    'asset_type',
                    $filters['asset_type']
                )
            );
        }

        if (isset($filters['meter_mode'])) {
            $query->where(
                'meter_mode',
                $filters['meter_mode']
            );
        }

        $page = $query
            ->orderByDesc('id')
            ->paginate(
                (int) ($filters['per_page'] ?? 15)
            );

        return ApiResponse::success(
            data: $page->getCollection()
                ->map(
                    static fn(
                        MaintenanceVehicleProfile $profile
                    ): array => (new MaintenanceVehicleProfileResource($profile))
                        ->toArray($request)
                )
                ->values()
                ->all(),

            message: 'Daftar kendaraan berhasil diambil.',

            meta: [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]
        );
    }

    public function show(
        Request $request,
        MaintenanceVehicleProfile $profile
    ): JsonResponse {
        Gate::authorize('view', $profile);

        $profile->load([
            'asset.company',
            'creator:id,name',
            'updater:id,name',
        ]);

        return ApiResponse::success(
            data: (new MaintenanceVehicleProfileResource($profile))
                ->toArray($request),
            message: 'Detail kendaraan berhasil diambil.'
        );
    }

    public function store(
        SaveMaintenanceVehicleProfileRequest $request,
        SaveMaintenanceVehicleProfile $action
    ): JsonResponse {
        try {
            $profile = $action->create(
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success(
                data: (new MaintenanceVehicleProfileResource($profile))
                    ->toArray($request),
                message: 'Profil kendaraan berhasil dibuat.',
                status: 201
            );
        } catch (VehicleProfileConflict $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                409
            );
        }
    }

    public function update(
        SaveMaintenanceVehicleProfileRequest $request,
        MaintenanceVehicleProfile $profile,
        SaveMaintenanceVehicleProfile $action
    ): JsonResponse {
        try {
            $updated = $action->update(
                (int) $profile->id,
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success(
                data: (new MaintenanceVehicleProfileResource($updated))->toArray($request),
                message: 'Profil kendaraan berhasil diperbarui.'
            );
        } catch (VehicleProfileConflict $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                409
            );
        }
    }
}
