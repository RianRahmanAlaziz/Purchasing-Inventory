<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Asset\Actions\AssetWorkflow;
use App\Domain\Asset\Models\OfficeAsset;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Inventory\AssetActionRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OfficeAssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'company_id' => ['sometimes', 'integer', Rule::exists('companies', 'id')],
            'item_id' => ['sometimes', 'integer', Rule::exists('items', 'id')],
            'status' => ['sometimes', Rule::in(['available', 'in_transit', 'in_use', 'damaged'])],
            'search' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = OfficeAsset::query()->whereHas('item', fn ($q) => $q->where('item_type', 'office_asset'))
            ->with(['item:id,category_id,sku,name,unit,item_type', 'item.category:id,code,name', 'company:id,name']);
        foreach (['company_id', 'item_id', 'status'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['search'])) {
            $query->where(fn ($q) => $q->where('asset_code', 'like', '%'.$filters['search'].'%')
                ->orWhere('holder_name', 'like', '%'.$filters['search'].'%')
                ->orWhereHas('item', fn ($item) => $item->where('name', 'like', '%'.$filters['search'].'%')));
        }
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success($page->items(), 'Daftar aset kantor.', meta: ['pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function options(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'item_id' => ['sometimes', 'integer', Rule::exists('items', 'id')],
            'status' => ['sometimes', Rule::in(['available'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = OfficeAsset::query()->where('company_id', $filters['company_id'])->where('status', 'available')
            ->whereHas('item', fn ($q) => $q->where('item_type', 'office_asset')->where('is_active', true))
            ->with(['item:id,name', 'company:id,name']);
        if (isset($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }
        $page = $query->orderBy('id')->paginate((int) ($filters['per_page'] ?? 100));
        $rows = $page->getCollection()->map(fn ($asset): array => [
            'id' => $asset->id, 'asset_code' => $asset->asset_code, 'item_id' => $asset->item_id,
            'company_id' => $asset->company_id, 'item_name' => $asset->item?->name,
            'company_name' => $asset->company?->name, 'status' => 'available',
        ])->all();

        return ApiResponse::success($rows, meta: ['pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function show(OfficeAsset $officeAsset): JsonResponse
    {
        return ApiResponse::success($officeAsset->load(['item:id,category_id,sku,name,unit,item_type', 'item.category:id,code,name', 'company:id,name', 'events.actor:id,name', 'events.fromCompany:id,name', 'events.toCompany:id,name']));
    }

    public function assign(AssetActionRequest $request, OfficeAsset $officeAsset, AssetWorkflow $workflow): JsonResponse
    {
        return $this->perform($request, $officeAsset, $workflow, 'assign');
    }

    public function reassign(AssetActionRequest $request, OfficeAsset $officeAsset, AssetWorkflow $workflow): JsonResponse
    {
        return $this->perform($request, $officeAsset, $workflow, 'reassign');
    }

    public function returnAsset(AssetActionRequest $request, OfficeAsset $officeAsset, AssetWorkflow $workflow): JsonResponse
    {
        return $this->perform($request, $officeAsset, $workflow, 'return');
    }

    public function changeCondition(AssetActionRequest $request, OfficeAsset $officeAsset, AssetWorkflow $workflow): JsonResponse
    {
        return $this->perform($request, $officeAsset, $workflow, 'condition');
    }

    private function perform(AssetActionRequest $request, OfficeAsset $asset, AssetWorkflow $workflow, string $action): JsonResponse
    {
        try {
            return ApiResponse::success($workflow->transition($asset, $request->user(), $action, $request->validated()), 'Perubahan aset tercatat.');
        } catch (StockConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }
    }
}
