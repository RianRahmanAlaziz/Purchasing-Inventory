<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Item\Models\Item;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Inventory\StoreStockAdjustmentRequest;
use App\Http\Resources\Api\V1\StockAdjustmentResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StockAdjustmentController extends Controller
{
    public function store(
        StoreStockAdjustmentRequest $request,
        Item $item,
        StockService $stock,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $adjustment = $stock->adjustTo(
                itemId: (int) $item->id,
                expectedBalance: $data['expected_quantity'],
                targetBalance: $data['target_quantity'],
                reason: $data['reason'],
                actorId: (int) $request->user()->id,
                idempotencyKey: $data['idempotency_key'],
            );
        } catch (StockConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }

        return ApiResponse::success(
            data: (new StockAdjustmentResource($adjustment->load('movement')))->toArray($request),
            message: 'Penyesuaian stok berhasil diproses.',
            status: $adjustment->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'item_id' => ['sometimes', 'integer', Rule::exists('items', 'id')],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = StockAdjustment::query()->with('movement');
        if (isset($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(
                fn (StockAdjustment $record): array => (new StockAdjustmentResource($record))->toArray($request)
            )->values()->all(),
            message: 'Riwayat stock adjustment berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function show(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        return ApiResponse::success(
            data: (new StockAdjustmentResource($stockAdjustment->load('movement')))->toArray($request),
            message: 'Detail stock adjustment berhasil diambil.',
        );
    }
}
