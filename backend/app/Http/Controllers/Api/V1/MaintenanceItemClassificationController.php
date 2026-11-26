<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Item\Models\Item;
use App\Domain\Maintenance\Actions\UpdateItemAssetClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Maintenance\UpdateItemClassificationRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class MaintenanceItemClassificationController extends Controller
{
    public function __invoke(
        UpdateItemClassificationRequest $request,
        Item $item,
        UpdateItemAssetClassification $action,
    ): JsonResponse {
        $data = $request->validated();

        $updated = $action->execute(
            itemId: (int) $item->id,
            isAssetTrackable: (bool) $data['is_asset_trackable'],
            reason: $data['reason'],
            actorId: (int) $request->user()->id,
        );

        return ApiResponse::success(
            data: [
                'id' => $updated->id,
                'sku' => $updated->sku,
                'name' => $updated->name,
                'is_asset_trackable' => (bool) $updated->is_asset_trackable,
            ],
            message: 'Klasifikasi aset berhasil diperbarui.',
        );
    }
}
