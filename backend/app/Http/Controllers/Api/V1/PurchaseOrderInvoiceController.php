<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Actions\SavePurchaseOrderInvoice;
use App\Domain\Purchasing\Models\Order;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PurchaseOrderInvoiceController extends Controller
{
    public function store(Request $request, string $purchaseOrder, SavePurchaseOrderInvoice $save): JsonResponse
    {
        $po = PurchaseOrder::query()->visibleTo($request->user())->findOrFail($purchaseOrder);
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'extensions:pdf,png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $po = $save->execute($po, $request->file('file'));

        return ApiResponse::success([
            'invoice_upload_status' => 'uploaded',
            'invoice_file_name' => $po->invoice_file_name,
        ], 'Dokumentasi invoice berhasil diunggah.');
    }

    public function download(Request $request, string $purchaseOrder): StreamedResponse
    {
        $po = PurchaseOrder::query()->visibleTo($request->user())->findOrFail($purchaseOrder);

        return $this->file($po);
    }

    public function downloadForOrder(Request $request, string $order): StreamedResponse
    {
        $record = Order::query()->visibleTo($request->user())->with('purchaseOrder')->findOrFail($order);

        return $this->file($record->purchaseOrder);
    }

    private function file(PurchaseOrder $po): StreamedResponse
    {
        $file = $po->invoiceFile();
        $disk = Storage::disk('local');
        abort_unless($file['path'] !== null && $disk->exists($file['path']), 404);

        return $disk->download($file['path'], $file['name'], [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
