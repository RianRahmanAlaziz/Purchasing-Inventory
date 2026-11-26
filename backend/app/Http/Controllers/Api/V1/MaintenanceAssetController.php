<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Maintenance\Actions\UpdateMaintenanceAssetMetadata;
use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Maintenance\UpdateMaintenanceAssetRequest;
use App\Http\Resources\Api\V1\MaintenanceAssetResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MaintenanceAssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            MaintenanceAsset::class
        );

        $filters = $request->validate([
            'search' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'company_id' => [
                'sometimes',
                'integer',
                Rule::exists('companies', 'id'),
            ],

            'asset_type' => [
                'sometimes',
                'string',
                'max:40',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
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

        $query = MaintenanceAsset::query()
            ->with([
                'company:id,name',
                'item:id,sku,name',
                'registrar:id,name',
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
                            'asset_code',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'asset_name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'serial_number',
                            'like',
                            "%{$search}%"
                        );
                }
            );
        }

        if (isset($filters['company_id'])) {
            $query->where(
                'company_id',
                $filters['company_id']
            );
        }

        if (isset($filters['asset_type'])) {
            $query->where(
                'asset_type',
                $filters['asset_type']
            );
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where(
                'is_active',
                filter_var(
                    $filters['is_active'],
                    FILTER_VALIDATE_BOOLEAN
                )
            );
        }

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                (int) ($filters['per_page'] ?? 15)
            );

        $assets = $page->getCollection()
            ->map(
                static fn(MaintenanceAsset $asset): array => (new MaintenanceAssetResource($asset))
                    ->toArray($request)
            )
            ->values()
            ->all();

        return ApiResponse::success(
            data: $assets,
            message: 'Daftar aset berhasil diambil.',
            meta: [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        );
    }

    public function show(
        Request $request,
        MaintenanceAsset $asset
    ): JsonResponse {
        Gate::authorize('view', $asset);

        $asset->load([
            'company:id,name',
            'item:id,sku,name',
            'registrar:id,name',
        ]);

        return ApiResponse::success(
            data: (new MaintenanceAssetResource($asset))
                ->toArray($request),
            message: 'Detail aset berhasil diambil.',
        );
    }

    public function update(
        UpdateMaintenanceAssetRequest $request,
        MaintenanceAsset $asset,
        UpdateMaintenanceAssetMetadata $action
    ): JsonResponse {
        $updated = $action->execute(
            assetId: (int) $asset->id,
            data: $request->validated(),
            actor: $request->user(),
        );

        return ApiResponse::success(
            data: (new MaintenanceAssetResource($updated))->toArray($request),
            message: 'Metadata aset berhasil diperbarui.',
        );
    }
}
