<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tax\Actions\DeleteTaxType;
use App\Domain\Tax\Actions\SaveTaxType;
use App\Domain\Tax\Exceptions\TaxConflictException;
use App\Domain\Tax\Models\TaxType;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tax\StoreTaxTypeRequest;
use App\Http\Requests\V1\Tax\UpdateTaxTypeRequest;
use App\Http\Resources\Api\V1\TaxTypeResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaxTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'direction' => ['sometimes', 'in:addition,withholding'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = TaxType::query();

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(static function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }
        if (isset($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var(
                $filters['is_active'], FILTER_VALIDATE_BOOLEAN
            ));
        }

        $page = $query->orderBy('code')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(
                static fn (TaxType $type): array => (new TaxTypeResource($type))->toArray($request)
            )->values()->all(),
            message: 'Daftar jenis pajak berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function show(Request $request, TaxType $taxType): JsonResponse
    {
        return ApiResponse::success(
            data: (new TaxTypeResource($taxType))->toArray($request),
            message: 'Detail jenis pajak berhasil diambil.',
        );
    }

    public function store(StoreTaxTypeRequest $request, SaveTaxType $action): JsonResponse
    {
        $type = $action->create($request->validated());

        return ApiResponse::success(
            data: (new TaxTypeResource($type))->toArray($request),
            message: 'Jenis pajak berhasil dibuat.',
            status: 201,
        );
    }

    public function update(
        UpdateTaxTypeRequest $request,
        TaxType $taxType,
        SaveTaxType $action,
    ): JsonResponse {
        try {
            $type = $action->update($taxType, $request->validated());
        } catch (TaxConflictException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            data: (new TaxTypeResource($type))->toArray($request),
            message: 'Jenis pajak berhasil diperbarui.',
        );
    }

    public function destroy(TaxType $taxType, DeleteTaxType $action): JsonResponse
    {
        try {
            $action->execute($taxType);
        } catch (TaxConflictException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(data: null, message: 'Jenis pajak berhasil dihapus.');
    }
}
