<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Supplier\Actions\DeleteSupplier;
use App\Domain\Supplier\Actions\SaveSupplier;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Support\SupplierName;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Supplier\StoreSupplierRequest;
use App\Http\Requests\V1\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Supplier::query();

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = trim($filters['search']);
            $like = '%'.self::escapeLike(SupplierName::normalized($term)).'%';
            $query->where(static function (Builder $q) use ($like): void {
                $q->where('normalized_name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where(
                'is_active',
                filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)
            );
        }

        $page = $query->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        $items = $page->getCollection()
            ->map(static fn (Supplier $supplier): array => (new SupplierResource($supplier))->toArray($request))
            ->values()->all();

        return ApiResponse::success($items, 'Daftar distributor berhasil diambil.', meta: [
            'pagination' => self::pagination($page),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        $term = SupplierName::normalized($data['q']);
        if (mb_strlen($term) < 2) {
            return ApiResponse::error('Minimal 2 karakter pencarian.', 422, [
                'q' => ['Minimal 2 karakter pencarian.'],
            ]);
        }

        $rows = Supplier::query()
            ->where('is_active', true)
            ->where('normalized_name', 'like', '%'.self::escapeLike($term).'%')
            ->orderBy('name')
            ->limit((int) ($data['limit'] ?? 10))
            ->get(['id', 'code', 'name']);

        return ApiResponse::success($rows, 'Saran distributor berhasil diambil.');
    }

    public function store(
        StoreSupplierRequest $request,
        SaveSupplier $action
    ): JsonResponse {
        try {
            $supplier = $action->create($request->validated());
        } catch (QueryException $exception) {
            return $this->duplicateOrRethrow($exception);
        }

        return ApiResponse::success(
            (new SupplierResource($supplier))->toArray($request),
            'Distributor berhasil ditambahkan.',
            201
        );
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        return ApiResponse::success(
            (new SupplierResource($supplier))->toArray($request),
            'Detail distributor berhasil diambil.'
        );
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier,
        SaveSupplier $action
    ): JsonResponse {
        try {
            $updated = $action->update($supplier, $request->validated());
        } catch (QueryException $exception) {
            return $this->duplicateOrRethrow($exception);
        }

        return ApiResponse::success(
            (new SupplierResource($updated))->toArray($request),
            'Distributor berhasil diperbarui.'
        );
    }

    public function destroy(Supplier $supplier, DeleteSupplier $action): JsonResponse
    {
        if (! $action->execute($supplier)) {
            return ApiResponse::error(
                'Distributor memiliki riwayat transaksi dan tidak dapat dihapus. Nonaktifkan saja.',
                409
            );
        }

        return ApiResponse::success(null, 'Distributor berhasil dihapus.');
    }

    public function purchaseOrders(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        // DB::table dipakai karena Model PurchaseOrder belum dikerjakan.
        // Termasuk PO yang telah di-soft-delete untuk kepentingan histori.
        $page = DB::table('purchase_orders')
            ->where('supplier_id', $supplier->getKey())
            ->select([
                'id', 'po_number', 'order_date', 'status',
                'supplier_name_snapshot', 'supplier_code_snapshot',
                'subtotal', 'total_amount', 'deleted_at',
            ])
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        $items = collect($page->items())
            ->map(static fn (object $po): array => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'order_date' => $po->order_date,
                'status' => $po->status,
                'supplier_name_at_order' => $po->supplier_name_snapshot,
                'supplier_code_at_order' => $po->supplier_code_snapshot,
                'subtotal' => $po->subtotal,
                'total_amount' => $po->total_amount,
                'is_archived' => $po->deleted_at !== null,
            ])->all();

        return ApiResponse::success(
            $items,
            'Riwayat PO distributor berhasil diambil.',
            meta: ['pagination' => self::pagination($page)]
        );
    }

    private static function pagination($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function duplicateOrRethrow(QueryException $exception): JsonResponse
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            throw $exception;
        }

        return ApiResponse::error(
            'Kode atau nama distributor sudah digunakan.',
            409
        );
    }
}
