<?php

declare(strict_types=1);

namespace App\Domain\Document\Actions;

use App\Domain\Document\Models\Document;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveDocument
{
    public function save(array $data, int $actor, ?Document $document = null): Document
    {
        $uploaded = null;
        $oldPath = null;
        try {
            $saved = DB::transaction(function () use ($data, $actor, $document, &$uploaded, &$oldPath): Document {
                $locked = $document ? Document::query()->lockForUpdate()->findOrFail($document->id) : new Document;
                if ($locked->exists && ! in_array($locked->status, ['not_uploaded', 'uploaded'], true)) {
                    abort(409, 'Dokumen yang sudah dikirim tidak dapat diedit.');
                }
                $values = array_merge($locked->only(['supplier_id', 'purchase_order_id', 'document_type', 'document_date', 'notes']), $data);
                if (! Supplier::query()->lockForUpdate()->findOrFail($values['supplier_id'])->is_active) {
                    throw ValidationException::withMessages(['supplier_id' => ['Supplier harus aktif.']]);
                }
                if (! empty($values['purchase_order_id'])) {
                    $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($values['purchase_order_id']);
                    if ((int) $po->supplier_id !== (int) $values['supplier_id']) {
                        throw ValidationException::withMessages(['purchase_order_id' => ['PO harus sesuai supplier.']]);
                    }
                }
                $file = $values['file'] ?? null;
                unset($values['file']);
                if ($file instanceof UploadedFile) {
                    $uploaded ??= $file->store('documents', 'local');
                    if ($uploaded === false) {
                        abort(503, 'Berkas belum dapat disimpan. Coba lagi.');
                    }
                    $oldPath = $locked->file_path;
                    $values += ['file_path' => $uploaded, 'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                        'file_mime' => $file->getMimeType(), 'file_size' => $file->getSize(), 'status' => 'uploaded'];
                }
                if (! $locked->exists) {
                    $values += ['document_number' => 'DOC-'.Str::ulid(), 'created_by' => $actor, 'status' => 'not_uploaded'];
                }
                $values['updated_by'] = $actor;
                $locked->fill($values)->save();

                return $locked->refresh()->load('supplier', 'purchaseOrder');
            }, attempts: 3);
        } catch (\Throwable $cause) {
            if ($uploaded) {
                Storage::disk('local')->delete($uploaded);
            }
            throw $cause;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return $saved;
    }

    public function transition(Document $document, string $next, int $actor): Document
    {
        return DB::transaction(function () use ($document, $next, $actor): Document {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
            $expected = ['sent' => 'uploaded', 'received' => 'sent', 'verified' => 'received'];
            if ($locked->status !== $next) {
                if (($expected[$next] ?? null) !== $locked->status || ! $locked->file_path
                    || ! Storage::disk('local')->exists($locked->file_path)) {
                    abort(409, 'Tahap dokumen atau berkas belum sesuai.');
                }
                $locked->update(['status' => $next, $next.'_at' => now(), $next.'_by' => $actor, 'updated_by' => $actor]);
            }

            return $locked->refresh()->load('supplier', 'purchaseOrder');
        }, attempts: 3);
    }

    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
            if (! in_array($locked->status, ['not_uploaded', 'uploaded'], true)) {
                abort(409, 'Dokumen yang sudah dikirim tidak dapat dihapus.');
            }
            $locked->delete();
        }, attempts: 3);
    }
}
