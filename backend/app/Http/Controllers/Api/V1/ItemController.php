<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Item\Actions\DeleteItem;
use App\Domain\Item\Actions\SaveItem;
use App\Domain\Item\Models\Item;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Item\StoreItemRequest;
use App\Http\Requests\V1\Item\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'low_stock' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Item::query()->withStockAlert()
            ->with('category:id,code,name,is_active');

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->where(static function (Builder $q) use ($search): void {
                $q->where('items.name', 'like', "%{$search}%")
                    ->orWhere('items.sku', 'like', "%{$search}%");
            });
        }

        if (isset($filters['category_id'])) {
            $query->where('items.category_id', $filters['category_id']);
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where(
                'items.is_active',
                filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN),
            );
        }

        if (array_key_exists('low_stock', $filters)) {
            if (filter_var($filters['low_stock'], FILTER_VALIDATE_BOOLEAN)) {
                $query->lowStock();
            } else {
                $query->whereColumn(
                    'items.stock_quantity',
                    '>',
                    'items.minimum_stock'
                );
            }
        }

        $page = $query->orderBy('items.name')->orderBy('items.id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()
                ->map(
                    static fn (Item $item): array => (new ItemResource($item))->toArray($request)
                )->values()->all(),
            message: 'Daftar barang berhasil diambil.',
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

    public function show(Request $request, Item $item): JsonResponse
    {
        $item = Item::query()->withStockAlert()
            ->with('category:id,code,name,is_active')
            ->findOrFail($item->getKey());

        return ApiResponse::success(
            data: (new ItemResource($item))->toArray($request),
            message: 'Detail barang berhasil diambil.',
        );
    }

    public function store(StoreItemRequest $request, SaveItem $action): JsonResponse
    {
        $item = $action->create($request->validated());

        return ApiResponse::success(
            data: (new ItemResource($item))->toArray($request),
            message: 'Barang berhasil dibuat.',
            status: 201,
        );
    }

    public function update(
        UpdateItemRequest $request,
        Item $item,
        SaveItem $action,
    ): JsonResponse {
        $updated = $action->update($item, $request->validated());

        return ApiResponse::success(
            data: (new ItemResource($updated))->toArray($request),
            message: 'Barang berhasil diperbarui.',
        );
    }

    public function destroy(Item $item, DeleteItem $action): JsonResponse
    {
        if (! $action->execute($item)) {
            return ApiResponse::error(
                'Barang tidak dapat dihapus karena memiliki saldo stok atau riwayat transaksi.',
                409,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'Barang berhasil dihapus.',
        );
    }
}
