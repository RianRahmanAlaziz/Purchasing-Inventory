<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class SavePurchaseOrderInvoice
{
    public function execute(PurchaseOrder $po, UploadedFile $file): PurchaseOrder
    {
        $path = $file->store('purchase-order-invoices', 'local');
        abort_if($path === false, 503, 'Berkas invoice belum dapat disimpan. Coba lagi.');

        $original = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $original) ?? '', 0, 255);
        try {
            return DB::transaction(function () use ($po, $path, $name, $file): PurchaseOrder {
                $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
                $oldPath = $locked->invoice_file_path;
                $locked->invoice_file_path = $path;
                $locked->invoice_file_name = $name !== '' ? $name : 'invoice.'.$file->guessExtension();
                $locked->save();

                if ($oldPath !== null) {
                    DB::afterCommit(static function () use ($oldPath): void {
                        try {
                            Storage::disk('local')->delete($oldPath);
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    });
                }

                return $locked;
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }
}
