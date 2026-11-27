<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Transfer\Actions\TransferWorkflow;
use App\Domain\Transfer\Models\InventoryTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Inventory\StoreInventoryTransferRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InventoryTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'received', 'cancelled'])],
            'company_id' => ['sometimes', 'integer', Rule::exists('companies', 'id')],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = InventoryTransfer::query()->with(['fromCompany:id,name', 'toCompany:id,name', 'items.item:id,sku,name,unit,item_type']);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['company_id'])) {
            $query->where(fn ($q) => $q->where('from_company_id', $filters['company_id'])->orWhere('to_company_id', $filters['company_id']));
        }
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success($page->items(), 'Daftar transfer antar-PT.', meta: ['pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function show(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        return ApiResponse::success($inventoryTransfer->load(['fromCompany:id,name', 'toCompany:id,name', 'items.item:id,sku,name,unit,item_type', 'events.actor:id,name']));
    }

    public function store(StoreInventoryTransferRequest $request, TransferWorkflow $workflow): JsonResponse
    {
        return $this->perform(fn () => $workflow->save($request->validated(), $request->user()), 'Draft transfer dibuat.', 201);
    }

    public function update(StoreInventoryTransferRequest $request, InventoryTransfer $inventoryTransfer, TransferWorkflow $workflow): JsonResponse
    {
        return $this->perform(fn () => $workflow->save($request->validated(), $request->user(), $inventoryTransfer), 'Draft transfer diperbarui.');
    }

    public function send(Request $request, InventoryTransfer $inventoryTransfer, TransferWorkflow $workflow): JsonResponse
    {
        return $this->perform(fn () => $workflow->send($inventoryTransfer, $request->user()), 'Transfer dikirim. Barang tercatat dalam perjalanan.');
    }

    public function receive(Request $request, InventoryTransfer $inventoryTransfer, TransferWorkflow $workflow): JsonResponse
    {
        return $this->perform(fn () => $workflow->receive($inventoryTransfer, $request->user()), 'Transfer diterima di PT tujuan.');
    }

    public function cancel(Request $request, InventoryTransfer $inventoryTransfer, TransferWorkflow $workflow): JsonResponse
    {
        return $this->perform(fn () => $workflow->cancel($inventoryTransfer, $request->user()), 'Draft transfer dibatalkan.');
    }

    private function perform(\Closure $work, string $message, int $status = 200): JsonResponse
    {
        try {
            return ApiResponse::success($work(), $message, $status);
        } catch (StockConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }
    }
}
