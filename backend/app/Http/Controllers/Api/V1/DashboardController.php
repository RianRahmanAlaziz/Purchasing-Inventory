<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\DashboardActivityService;
use App\Domain\Reporting\Services\DashboardReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Reporting\DashboardFilterRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DashboardController extends Controller
{
    public function overview(
        DashboardFilterRequest $request,
        DashboardReportService $report
    ): JsonResponse {
        return ApiResponse::success(
            data: $report->overview($request->user(), $request->validated()),
            message: 'Ringkasan operasional berhasil diambil.',
        );
    }

    public function activity(
        DashboardFilterRequest $request,
        DashboardActivityService $activity
    ): JsonResponse {
        $result = $activity->paginate($request->user(), $request->validated());

        return ApiResponse::success(
            data: $result['data'],
            message: 'Aktivitas operasional berhasil diambil.',
            meta: $result['meta'],
        );
    }

    public function lowStock(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = DB::table('items AS i')
            ->join('categories AS c', 'c.id', '=', 'i.category_id')
            ->where('i.is_active', true)
            ->whereColumn('i.stock_quantity', '<=', 'i.minimum_stock');

        if (isset($filters['category_id'])) {
            $query->where('i.category_id', $filters['category_id']);
        }
        if (isset($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('i.sku', 'like', '%'.$search.'%')
                    ->orWhere('i.name', 'like', '%'.$search.'%');
            });
        }

        $page = $query->orderBy('i.name')->orderBy('i.id')
            ->paginate((int) ($filters['per_page'] ?? 15), [
                'i.id', 'i.category_id', 'c.name AS category_name',
                'i.sku', 'i.name', 'i.unit', 'i.stock_quantity', 'i.minimum_stock',
            ]);

        return ApiResponse::success(
            data: $page->getCollection()->map(static fn (object $item): array => [
                'id' => (int) $item->id,
                'category_id' => (int) $item->category_id,
                'category_name' => $item->category_name,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit,
                'stock_quantity' => (string) $item->stock_quantity,
                'minimum_stock' => (string) $item->minimum_stock,
            ])->values()->all(),
            message: 'Daftar barang stok menipis berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }
}
