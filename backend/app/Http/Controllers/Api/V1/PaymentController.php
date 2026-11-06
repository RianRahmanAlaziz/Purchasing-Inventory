<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Actions\SaveFinance;
use App\Domain\Finance\Models\Payment;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Finance\SavePaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = Payment::query()->with(['invoice.supplier', 'invoice.purchaseOrder'])->orderByDesc('id')->paginate($filters['per_page'] ?? 15);

        return ApiResponse::success($page->getCollection()->map(fn ($row) => (new PaymentResource($row))->toArray($request))->all(),
            meta: ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        return ApiResponse::success((new PaymentResource($payment->load('invoice.supplier', 'invoice.purchaseOrder')))->toArray($request));
    }

    public function store(SavePaymentRequest $request, SaveFinance $save): JsonResponse
    {
        $row = $save->payment($request->validated(), (int) $request->user()->id);

        return ApiResponse::success((new PaymentResource($row))->toArray($request), 'Pembayaran tersimpan.', 201);
    }

    public function update(SavePaymentRequest $request, Payment $payment, SaveFinance $save): JsonResponse
    {
        $row = $save->payment($request->validated(), (int) $request->user()->id, $payment);

        return ApiResponse::success((new PaymentResource($row))->toArray($request));
    }

    public function destroy(Payment $payment, SaveFinance $save): JsonResponse
    {
        $save->deletePayment($payment);

        return ApiResponse::success(null, 'Data dihapus.');
    }

    public function verify(Request $request, Payment $payment, SaveFinance $save): JsonResponse
    {
        return ApiResponse::success((new PaymentResource($save->decide($payment, (int) $request->user()->id, true, null)))->toArray($request));
    }

    public function reject(Request $request, Payment $payment, SaveFinance $save): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return ApiResponse::success((new PaymentResource($save->decide($payment, (int) $request->user()->id, false, $data['reason'])))->toArray($request));
    }
}
