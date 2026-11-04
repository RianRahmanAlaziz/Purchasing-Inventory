<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Enums\OrderStatus;
use App\Domain\Purchasing\Exceptions\OrderConflict;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Services\OrderReceiptProgress;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'supplier_id' => ['sometimes', 'integer', Rule::exists('suppliers', 'id')],
            'po_number' => ['sometimes', 'string', 'max:60'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Order::query()->with('purchaseOrder');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['supplier_id'])) {
            $query->whereHas('purchaseOrder', fn ($q) => $q->where('supplier_id', $filters['supplier_id']));
        }
        if (isset($filters['po_number'])) {
            $number = trim($filters['po_number']);
            $query->whereHas('purchaseOrder', fn ($q) => $q->where('po_number', 'like', '%'.$number.'%'));
        }

        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(
                fn (Order $order): array => (new OrderResource($order))->toArray($request)
            )->values()->all(),
            message: 'Daftar Order berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function summary(): JsonResponse
    {
        $counts = Order::query()
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $data = [];
        foreach (OrderStatus::cases() as $status) {
            $data[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return ApiResponse::success(
            data: ['total' => array_sum($data), 'by_status' => $data],
            message: 'Ringkasan Order berhasil diambil.',
        );
    }

    public function show(
        Request $request,
        Order $order,
        OrderReceiptProgress $progress
    ): JsonResponse {
        $order->load('purchaseOrder.items');
        if ($order->purchaseOrder === null) {
            return ApiResponse::error('Purchase Order terkait tidak ditemukan.', 409);
        }

        try {
            $data = (new OrderResource($order))->toArray($request);
            $data['receiving_progress'] = $progress->calculate($order->purchaseOrder);

            return ApiResponse::success(
                data: $data,
                message: 'Detail Order berhasil diambil.',
            );
        } catch (OrderConflict $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }
    }

    public function receipts(Order $order): JsonResponse
    {
        // Hanya histori GRN (Pending tetap tampil, tetapi bukan stok masuk).
        $receipts = DB::table('goods_receipts')
            ->where('purchase_order_id', $order->purchase_order_id)
            ->orderByDesc('id')
            ->get([
                'id',
                'grn_number',
                'status',
                'received_date',
                'confirmed_at',
                'created_by',
                'confirmed_by',
            ]);

        return ApiResponse::success(
            data: $receipts,
            message: 'Riwayat penerimaan Order berhasil diambil.',
        );
    }
}
