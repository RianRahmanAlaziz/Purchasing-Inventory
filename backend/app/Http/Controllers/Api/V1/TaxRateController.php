<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tax\Actions\DeleteTaxRate;
use App\Domain\Tax\Actions\SaveTaxRate;
use App\Domain\Tax\Exceptions\TaxConflictException;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tax\StoreTaxRateRequest;
use App\Http\Requests\V1\Tax\UpdateTaxRateRequest;
use App\Http\Resources\Api\V1\TaxRateResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TaxRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tax_type_id' => ['sometimes', 'integer', Rule::exists('tax_types', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = TaxRate::query()->with('taxType');
        if (isset($filters['tax_type_id'])) {
            $query->where('tax_type_id', $filters['tax_type_id']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var(
                $filters['is_active'], FILTER_VALIDATE_BOOLEAN
            ));
        }
        if (isset($filters['date'])) {
            $date = $filters['date'];
            $query->whereDate('effective_from', '<=', $date)
                ->where(static function ($q) use ($date): void {
                    $q->whereNull('effective_until')
                        ->orWhereDate('effective_until', '>=', $date);
                });
        }

        $page = $query->orderBy('tax_type_id')->orderByDesc('effective_from')
            ->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(
                static fn (TaxRate $rate): array => (new TaxRateResource($rate))->toArray($request)
            )->values()->all(),
            message: 'Daftar tarif pajak berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function show(Request $request, TaxRate $taxRate): JsonResponse
    {
        return ApiResponse::success(
            data: (new TaxRateResource($taxRate->load('taxType')))->toArray($request),
            message: 'Detail tarif pajak berhasil diambil.',
        );
    }

    public function store(StoreTaxRateRequest $request, SaveTaxRate $action): JsonResponse
    {
        try {
            $rate = $action->create($request->validated());
        } catch (TaxConflictException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            data: (new TaxRateResource($rate))->toArray($request),
            message: 'Tarif pajak berhasil dibuat.',
            status: 201,
        );
    }

    public function update(
        UpdateTaxRateRequest $request,
        TaxRate $taxRate,
        SaveTaxRate $action,
    ): JsonResponse {
        try {
            $rate = $action->update($taxRate, $request->validated());
        } catch (TaxConflictException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            data: (new TaxRateResource($rate))->toArray($request),
            message: 'Tarif pajak berhasil diperbarui.',
        );
    }

    public function destroy(TaxRate $taxRate, DeleteTaxRate $action): JsonResponse
    {
        try {
            $action->execute($taxRate);
        } catch (TaxConflictException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(data: null, message: 'Tarif pajak berhasil dihapus.');
    }
}
