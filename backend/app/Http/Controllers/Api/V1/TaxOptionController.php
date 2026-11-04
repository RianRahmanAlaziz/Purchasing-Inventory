<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TaxRateResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaxOptionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $date = $data['date'] ?? now()->toDateString();

        $rates = TaxRate::query()
            ->with('taxType')
            ->where('is_active', true)
            ->whereHas('taxType', static fn ($q) => $q->where('is_active', true))
            ->whereDate('effective_from', '<=', $date)
            ->where(static function ($q) use ($date): void {
                $q->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date);
            })
            ->orderBy('tax_type_id')
            ->orderBy('effective_from')
            ->get();

        return ApiResponse::success(
            data: $rates->map(static fn (TaxRate $rate): array => (new TaxRateResource($rate))->toArray($request)
            )->values()->all(),
            message: 'Opsi pajak yang berlaku berhasil diambil.',
            meta: ['date' => $date],
        );
    }
}
