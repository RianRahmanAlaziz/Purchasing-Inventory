<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Company\Models\Company;
use App\Domain\Inventory\Actions\ReconcileInventory;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Support\StockDecimal;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CompanyInventoryController extends Controller
{
    public function companies()
    {
        return ApiResponse::success(Company::query()->orderBy('name')->get(['id', 'name'])->toArray());
    }

    public function reconcile(Request $request, ReconcileInventory $action)
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id,deleted_at,NULL'],
            'quantity' => ['required', 'string', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,3})?$/D'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        try {
            return ApiResponse::success($action->execute($data, (int) $request->user()->id)->toArray());
        } catch (StockConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            return ApiResponse::error('Kunci rekonsiliasi sudah digunakan.', 409);
        }
    }

    public function overview(Request $request)
    {
        $data = $request->validate(['company_id' => ['sometimes', 'integer', 'exists:companies,id']]);
        $companyId = $data['company_id'] ?? null;
        $balances = DB::table('inventory_balances as b')->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('companies as c', 'c.id', '=', 'b.company_id')
            ->when($companyId, fn ($q) => $q->where('b.company_id', $companyId))
            ->where('i.awaiting_first_receipt', false)
            ->select('b.item_id', 'i.name as item_name', 'b.company_id', 'c.name as company_name', 'b.quantity', 'i.minimum_stock', 'i.is_active')->get();
        $assets = DB::table('office_assets')->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $transit = DB::table('inventory_transfer_items as l')->join('inventory_transfers as t', 't.id', '=', 'l.inventory_transfer_id')
            ->where('t.status', 'sent')->when($companyId, fn ($q) => $q->where(function ($q) use ($companyId): void {
                $q->where('t.from_company_id', $companyId)->orWhere('t.to_company_id', $companyId);
            }))->sum('l.quantity');
        $available = StockDecimal::format($balances->reduce(fn (int $sum, $row): int => $sum + StockDecimal::parse((string) $row->quantity), 0));

        $usedAssets = (clone $assets)->where('status', 'in_use')->count();
        $damagedAssets = (clone $assets)->where('status', 'damaged')->count();
        $controlledTransit = $companyId === null ? $transit : DB::table('inventory_transfer_items as l')
            ->join('inventory_transfers as t', 't.id', '=', 'l.inventory_transfer_id')
            ->where('t.status', 'sent')->where('t.from_company_id', $companyId)->sum('l.quantity');
        $controlled = StockDecimal::parse($available) + StockDecimal::parse((string) $controlledTransit) + ($usedAssets + $damagedAssets) * 1000;

        return ApiResponse::success([
            'balances' => $balances->all(),
            'totals' => ['available' => $available, 'in_transit' => (string) $transit,
                'assets_available' => (clone $assets)->where('status', 'available')->count(),
                'assets_in_use' => $usedAssets, 'assets_damaged' => $damagedAssets,
                'controlled_quantity' => StockDecimal::format($controlled),
                'unallocated' => (string) DB::table('inventory_balances')->whereNull('company_id')->sum('quantity')],
            'low_stock' => $balances->filter(fn ($b) => $b->is_active && StockDecimal::parse((string) $b->quantity) <= StockDecimal::parse((string) $b->minimum_stock))->values()->all(),
        ]);
    }
}
