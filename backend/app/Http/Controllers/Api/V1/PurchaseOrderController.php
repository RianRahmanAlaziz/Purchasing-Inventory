<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domain\Purchasing\Actions\SaveDraftPurchaseOrder;
use App\Domain\Purchasing\Exceptions\PurchaseOrderConflict;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Purchasing\PoDecisionRequest;
use App\Http\Requests\V1\Purchasing\StorePurchaseOrderRequest;
use App\Http\Requests\V1\Purchasing\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'pending_approval', 'approved', 'sent', 'received_by_supplier', 'rejected', 'cancelled'])],
            'supplier_id' => ['sometimes', 'integer', Rule::exists('suppliers', 'id')],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = PurchaseOrder::query()->with(['items', 'taxes', 'approvals', 'order']);
        if (isset($filter['status'])) {
            $query->where('status', $filter['status']);
        }
        if (isset($filter['supplier_id'])) {
            $query->where('supplier_id', $filter['supplier_id']);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($filter['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(fn ($po): array => (new PurchaseOrderResource($po))->toArray($request))->all(),
            message: 'Daftar PO berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->respond($request, $purchaseOrder->load(['items', 'taxes', 'approvals', 'order', 'events']));
    }

    public function store(StorePurchaseOrderRequest $request, SaveDraftPurchaseOrder $action): JsonResponse
    {
        $po = $action->create($request->validated(), (int) $request->user()->id);

        return $this->respond($request, $po, 'Draft PO berhasil dibuat.', 201);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, SaveDraftPurchaseOrder $action): JsonResponse
    {
        return $this->perform($request, fn () => $action->update(
            $purchaseOrder,
            $request->validated(),
            (int) $request->user()->id
        ));
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderWorkflow $flow): JsonResponse
    {
        return $this->perform($request, fn () => $flow->submit($purchaseOrder, $request->user()));
    }

    public function approve(PoDecisionRequest $request, PurchaseOrder $purchaseOrder, string $step, PurchaseOrderWorkflow $flow): JsonResponse
    {
        return $this->perform($request, fn () => $flow->approve($purchaseOrder, $request->user(), $step));
    }

    public function reject(PoDecisionRequest $request, PurchaseOrder $purchaseOrder, string $step, PurchaseOrderWorkflow $flow): JsonResponse
    {
        return $this->perform($request, fn () => $flow->reject(
            $purchaseOrder,
            $request->user(),
            $step,
            $request->validated('reason')
        ));
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderWorkflow $flow): JsonResponse
    {
        return $this->perform($request, fn () => $flow->send($purchaseOrder, $request->user()));
    }

    public function supplierConfirm(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderWorkflow $flow): JsonResponse
    {
        return $this->perform($request, fn () => $flow->supplierConfirm($purchaseOrder, $request->user()));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderWorkflow $flow): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->perform($request, fn () => $flow->cancel($purchaseOrder, $request->user(), $data['reason']));
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderWorkflow $flow): JsonResponse
    {
        try {
            $flow->softDelete($purchaseOrder, $request->user());

            return ApiResponse::success(data: null, message: 'PO berhasil dihapus secara soft-delete.');
        } catch (PurchaseOrderConflict $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }
    }

    /** @param \Closure(): PurchaseOrder $action */
    private function perform(Request $request, \Closure $action): JsonResponse
    {
        try {
            return $this->respond($request, $action());
        } catch (PurchaseOrderConflict $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }
    }

    private function respond(Request $request, PurchaseOrder $po, string $message = 'PO berhasil diproses.', int $status = 200): JsonResponse
    {
        return ApiResponse::success(
            data: (new PurchaseOrderResource($po))->toArray($request),
            message: $message,
            status: $status,
        );
    }
}
