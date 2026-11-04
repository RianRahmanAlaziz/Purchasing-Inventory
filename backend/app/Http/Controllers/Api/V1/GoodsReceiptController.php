<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Purchasing\Exceptions\OrderConflict;
use App\Domain\Receiving\Actions\ConfirmGoodsReceipt;
use App\Domain\Receiving\Actions\SaveGoodsReceipt;
use App\Domain\Receiving\Exceptions\GoodsReceiptConflict;
use App\Domain\Receiving\Models\GoodsReceipt;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Receiving\StoreGoodsReceiptRequest;
use App\Http\Requests\V1\Receiving\UpdateGoodsReceiptRequest;
use App\Http\Resources\Api\V1\GoodsReceiptResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GoodsReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'purchase_order_id' => ['sometimes', 'integer', Rule::exists('purchase_orders', 'id')],
            'status' => ['sometimes', Rule::in([
                'pending',
                'fully_received',
                'partially_received',
                'discrepancy',
            ])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = GoodsReceipt::query()
            ->with(['purchaseOrder', 'items.purchaseOrderItem', 'items.movement']);
        if (isset($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', $filters['purchase_order_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(
                fn (GoodsReceipt $grn): array => (new GoodsReceiptResource($grn))->toArray($request)
            )->values()->all(),
            message: 'Daftar GRN berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        return $this->respond($request, $goodsReceipt->load([
            'purchaseOrder',
            'items.purchaseOrderItem',
            'items.movement',
        ]));
    }

    public function store(
        StoreGoodsReceiptRequest $request,
        SaveGoodsReceipt $action,
    ): JsonResponse {
        return $this->perform($request, fn () => $action->create(
            $request->validated(),
            (int) $request->user()->id
        ), 'GRN Pending berhasil dibuat.', 201);
    }

    public function update(
        UpdateGoodsReceiptRequest $request,
        GoodsReceipt $goodsReceipt,
        SaveGoodsReceipt $action,
    ): JsonResponse {
        return $this->perform($request, fn () => $action->update(
            $goodsReceipt,
            $request->validated()
        ), 'GRN Pending berhasil diperbarui.');
    }

    public function confirm(
        Request $request,
        GoodsReceipt $goodsReceipt,
        ConfirmGoodsReceipt $action,
    ): JsonResponse {
        return $this->perform($request, fn () => $action->execute(
            $goodsReceipt,
            (int) $request->user()->id
        ), 'GRN berhasil dikonfirmasi.');
    }

    /** @param \Closure(): GoodsReceipt $work */
    private function perform(
        Request $request,
        \Closure $work,
        string $message,
        int $status = 200,
    ): JsonResponse {
        try {
            return $this->respond($request, $work(), $message, $status);
        } catch (GoodsReceiptConflict|StockConflictException|OrderConflict $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }
    }

    private function respond(
        Request $request,
        GoodsReceipt $receipt,
        string $message = 'Detail GRN berhasil diambil.',
        int $status = 200,
    ): JsonResponse {
        return ApiResponse::success(
            data: (new GoodsReceiptResource($receipt))->toArray($request),
            message: $message,
            status: $status,
        );
    }
}
