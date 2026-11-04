<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Distribution\Actions\DistributionWorkflow;
use App\Domain\Distribution\Actions\RequestDistribution;
use App\Domain\Distribution\Enums\DistributionStatus;
use App\Domain\Distribution\Exceptions\DistributionConflict;
use App\Domain\Distribution\Models\Distribution;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Distribution\StoreDistributionRequest;
use App\Http\Resources\Api\V1\DistributionResource;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DistributionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $canViewAll = $actor->can('distribution.view');
        $mine = $actor->employee()->where('is_active', true)->value('id');

        if (! $canViewAll && ($mine === null || ! $actor->tokenCan('employee:self'))) {
            throw new AuthorizationException('Tidak memiliki akses daftar distribusi.');
        }

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(array_column(DistributionStatus::cases(), 'value'))],
            'employee_id' => ['sometimes', 'integer', Rule::exists('employees', 'id')],
            'mine' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Distribution::query()->with([
            'employee',
            'items.item',
            'items.movement',
            'events',
        ]);

        if (! $canViewAll || filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            if ($mine === null) {
                throw new AuthorizationException('Akun tidak terhubung dengan karyawan aktif.');
            }
            $query->where('employee_id', $mine);
        }

        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(
                fn (Distribution $distribution): array => $this->resource($distribution, $request)
            )->values()->all(),
            message: 'Daftar distribusi berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function show(Request $request, Distribution $distribution): JsonResponse
    {
        $this->ensureCanView($request, $distribution);

        return $this->respond($request, $distribution->load([
            'employee',
            'items.item',
            'items.movement',
            'events',
        ]));
    }

    public function store(StoreDistributionRequest $request, RequestDistribution $action): JsonResponse
    {
        return $this->perform($request, fn () => $action->execute(
            $request->validated(),
            $request->user()
        ), 'Permintaan distribusi dibuat.', 201);
    }

    public function approve(Request $request, Distribution $distribution, DistributionWorkflow $action): JsonResponse
    {
        return $this->perform(
            $request,
            fn () => $action->approve($distribution, $request->user()),
            'Distribusi disetujui.'
        );
    }

    public function reject(Request $request, Distribution $distribution, DistributionWorkflow $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->perform($request, fn () => $action->reject(
            $distribution,
            $request->user(),
            $data['reason']
        ), 'Distribusi ditolak.');
    }

    public function prepare(Request $request, Distribution $distribution, DistributionWorkflow $action): JsonResponse
    {
        return $this->perform(
            $request,
            fn () => $action->prepare($distribution, $request->user()),
            'Distribusi sedang disiapkan.'
        );
    }

    public function send(Request $request, Distribution $distribution, DistributionWorkflow $action): JsonResponse
    {
        return $this->perform(
            $request,
            fn () => $action->send($distribution, $request->user()),
            'Distribusi telah dikirim.'
        );
    }

    public function receive(Request $request, Distribution $distribution, DistributionWorkflow $action): JsonResponse
    {
        return $this->perform(
            $request,
            fn () => $action->receive($distribution, $request->user()),
            'Penerimaan distribusi dikonfirmasi.'
        );
    }

    private function ensureCanView(Request $request, Distribution $distribution): void
    {
        $actor = $request->user();
        if ($actor->can('distribution.view')) {
            return;
        }
        $mine = $actor->employee()->where('is_active', true)->value('id');
        if (
            $mine === null || (int) $mine !== (int) $distribution->employee_id
            || ! $actor->tokenCan('employee:self')
        ) {
            throw new AuthorizationException('Hanya boleh melihat distribusi milik sendiri.');
        }
    }

    /** @param \Closure():Distribution $work */
    private function perform(Request $request, \Closure $work, string $message, int $status = 200): JsonResponse
    {
        try {
            return $this->respond($request, $work(), $message, $status);
        } catch (DistributionConflict|StockConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }
    }

    private function respond(Request $request, Distribution $distribution, string $message = 'Detail distribusi.', int $status = 200): JsonResponse
    {
        return ApiResponse::success(
            data: $this->resource($distribution, $request),
            message: $message,
            status: $status,
        );
    }

    private function resource(Distribution $distribution, Request $request): array
    {
        return (new DistributionResource($distribution))->toArray($request);
    }
}
