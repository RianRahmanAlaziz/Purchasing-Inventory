<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\StockMovement;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'item_id' => ['sometimes', 'integer', Rule::exists('items', 'id')],
            'movement_type' => ['sometimes', Rule::in([
                'adjustment', 'grn_in', 'distribution_out',
            ])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = StockMovement::query()->with('item:id,sku,name,unit');

        if (isset($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        if (isset($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(
                fn (StockMovement $movement): array => (new StockMovementResource($movement))->toArray($request)
            )->values()->all(),
            message: 'Riwayat mutasi stok berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }
}
