<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Company\Actions\DeleteCompany;
use App\Domain\Company\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Company\SaveCompanyRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = Company::query()->orderBy('name')->paginate($filters['per_page'] ?? 15);

        return ApiResponse::success($page->getCollection()->map(fn ($company) => (new CompanyResource($company))->toArray($request))->all(),
            meta: ['pagination' => ['last_page' => $page->lastPage(), 'current_page' => $page->currentPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        return ApiResponse::success((new CompanyResource($company))->toArray($request));
    }

    public function store(SaveCompanyRequest $request): JsonResponse
    {
        $company = Company::query()->create($request->validated());

        return ApiResponse::success((new CompanyResource($company))->toArray($request), 'Profil PT tersimpan.', 201);
    }

    public function update(SaveCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->validated());

        return ApiResponse::success((new CompanyResource($company->refresh()))->toArray($request), 'Profil PT diperbarui.');
    }

    public function activate(Request $request, Company $company): JsonResponse
    {
        DB::transaction(function () use ($request, $company): void {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $request->user()->default_company_id = $company->id;
            $request->user()->save();
        }, attempts: 3);

        return ApiResponse::success(['default_company_id' => $company->id], 'PT aktif diperbarui.');
    }

    public function destroy(Company $company, DeleteCompany $action): JsonResponse
    {
        if (! $action->execute($company)) {
            return ApiResponse::error('PT masih memiliki stok, aset, atau transfer dalam perjalanan dan tidak dapat dihapus.', 409);
        }

        return ApiResponse::success(null, 'Profil PT dihapus. Identitas pada PO lama tetap tersimpan.');
    }
}
