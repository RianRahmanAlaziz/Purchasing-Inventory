<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Category\Actions\DeleteCategory;
use App\Domain\Category\Actions\SaveCategory;
use App\Domain\Category\Models\Category;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Category\StoreCategoryRequest;
use App\Http\Requests\V1\Category\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Category::query();

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = trim($filters['search']);
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where(
                'is_active',
                filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN),
            );
        }

        $page = $query->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()
                ->map(
                    static fn (Category $category): array => (new CategoryResource($category))->toArray($request)
                )->values()->all(),
            message: 'Daftar kategori berhasil diambil.',
            meta: [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        );
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        return ApiResponse::success(
            data: (new CategoryResource($category))->toArray($request),
            message: 'Detail kategori berhasil diambil.',
        );
    }

    public function store(StoreCategoryRequest $request, SaveCategory $action): JsonResponse
    {
        $category = $action->create($request->validated());

        return ApiResponse::success(
            data: (new CategoryResource($category))->toArray($request),
            message: 'Kategori berhasil dibuat.',
            status: 201,
        );
    }

    public function update(
        UpdateCategoryRequest $request,
        Category $category,
        SaveCategory $action,
    ): JsonResponse {
        $updated = $action->update($category, $request->validated());

        return ApiResponse::success(
            data: (new CategoryResource($updated))->toArray($request),
            message: 'Kategori berhasil diperbarui.',
        );
    }

    public function destroy(Category $category, DeleteCategory $action): JsonResponse
    {
        if (! $action->execute($category)) {
            return ApiResponse::error(
                'Kategori tidak dapat dihapus karena sudah digunakan oleh barang.',
                409,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'Kategori berhasil dihapus.',
        );
    }
}
