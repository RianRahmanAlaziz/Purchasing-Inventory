<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Actions\SaveFinance;
use App\Domain\Finance\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Finance\SaveInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = Invoice::query()->withBalances()->orderByDesc('id')->paginate($filters['per_page'] ?? 15);

        return ApiResponse::success($page->getCollection()->map(fn ($row) => (new InvoiceResource($row))->toArray($request))->all(),
            meta: ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        return ApiResponse::success((new InvoiceResource(Invoice::query()->withBalances()->findOrFail($invoice->id)))->toArray($request));
    }

    public function store(SaveInvoiceRequest $request, SaveFinance $save): JsonResponse
    {
        $row = $save->invoice($request->validated(), (int) $request->user()->id);

        return ApiResponse::success((new InvoiceResource($row))->toArray($request), 'Tagihan tersimpan.', 201);
    }

    public function update(SaveInvoiceRequest $request, Invoice $invoice, SaveFinance $save): JsonResponse
    {
        $row = $save->invoice($request->validated(), (int) $request->user()->id, $invoice);

        return ApiResponse::success((new InvoiceResource($row))->toArray($request));
    }

    public function destroy(Invoice $invoice, SaveFinance $save): JsonResponse
    {
        $save->deleteInvoice($invoice);

        return ApiResponse::success(null, 'Data dihapus.');
    }
}
