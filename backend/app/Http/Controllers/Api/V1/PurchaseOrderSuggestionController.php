<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Item\Models\Item;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurchaseOrderSuggestionController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = Category::query()->where('is_active', true)->orderBy('name')->paginate($data['per_page'] ?? 100, ['id', 'code', 'name']);

        return ApiResponse::success($page->items(), meta: ['pagination' => ['last_page' => $page->lastPage(), 'current_page' => $page->currentPage()]]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        [$pattern, $limit] = $this->filters($request);
        $latest = DB::table('purchase_orders')->selectRaw('MAX(id) AS id')->whereNull('deleted_at')
            ->whereRaw("supplier_name_snapshot LIKE ? ESCAPE '!'", [$pattern])
            ->groupBy('supplier_id', 'supplier_name_snapshot');
        $history = DB::table('purchase_orders as po')->joinSub($latest, 'latest', 'latest.id', '=', 'po.id')
            ->join('suppliers as supplier', 'supplier.id', '=', 'po.supplier_id')->where('supplier.is_active', true)
            ->orderByDesc('po.id')->limit($limit)
            ->get(['po.supplier_id', 'po.supplier_name_snapshot', 'po.supplier_address_snapshot', 'supplier.address', 'supplier.code'])
            ->map(fn ($row): array => ['id' => (int) $row->supplier_id, 'name' => $row->supplier_name_snapshot,
                'address' => $row->supplier_address_snapshot ?? $row->address ?? '', 'code' => $row->code, 'source' => 'history'])->all();
        $masters = Supplier::query()->where('is_active', true)->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
            ->orderBy('name')->limit($limit)->get()->map(fn ($supplier): array => [
                'id' => (int) $supplier->id, 'name' => $supplier->name, 'address' => $supplier->address ?? '',
                'code' => $supplier->code, 'source' => 'master',
            ])->all();

        return ApiResponse::success($this->merge($history, $masters, $limit));
    }

    public function items(Request $request): JsonResponse
    {
        [$pattern, $limit] = $this->filters($request);
        $latest = DB::table('purchase_order_items as line')->join('purchase_orders as po', 'po.id', '=', 'line.purchase_order_id')
            ->whereNull('po.deleted_at')->whereRaw("line.item_name_snapshot LIKE ? ESCAPE '!'", [$pattern])
            ->selectRaw('MAX(line.id) AS id')->groupBy('line.item_id', 'line.item_name_snapshot');
        $history = DB::table('purchase_order_items as line')->joinSub($latest, 'latest', 'latest.id', '=', 'line.id')
            ->join('items as item', 'item.id', '=', 'line.item_id')->join('categories as category', 'category.id', '=', 'item.category_id')
            ->where('item.is_active', true)->where('category.is_active', true)->orderByDesc('line.id')->limit($limit)
            ->get(['line.item_id', 'line.item_name_snapshot', 'line.unit_price', 'item.name', 'item.sku', 'item.unit', 'item.stock_quantity', 'item.category_id'])
            ->map(fn ($row): array => ['id' => (int) $row->item_id, 'name' => $row->item_name_snapshot,
                'stock_name' => $row->name, 'sku' => $row->sku, 'unit' => $row->unit,
                'unit_price' => (string) $row->unit_price, 'stock_quantity' => (string) $row->stock_quantity,
                'category_id' => (int) $row->category_id, 'source' => 'history'])->all();
        $stock = Item::query()->where('is_active', true)->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->where(fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]))
            ->orderBy('name')->orderBy('sku')->limit($limit)->get()->map(fn ($item): array => [
                'id' => (int) $item->id, 'name' => $item->name, 'stock_name' => $item->name,
                'sku' => $item->sku, 'unit' => $item->unit, 'unit_price' => (string) $item->last_purchase_price,
                'stock_quantity' => (string) $item->stock_quantity, 'category_id' => (int) $item->category_id, 'source' => 'stock',
            ])->all();

        return ApiResponse::success($this->merge($history, $stock, $limit));
    }

    private function filters(Request $request): array
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:200'], 'limit' => ['sometimes', 'integer', 'between:1,20']]);
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::squish($data['q']));

        return ['%'.$term.'%', (int) ($data['limit'] ?? 10)];
    }

    private function merge(array $history, array $masters, int $limit): array
    {
        $result = [];
        $seen = [];
        for ($index = 0; $index < $limit; $index++) {
            foreach ([$history[$index] ?? null, $masters[$index] ?? null] as $row) {
                if ($row === null) {
                    continue;
                }
                $key = $row['id'].'|'.Str::lower(Str::squish($row['name'])).'|'.($row['unit'] ?? '');
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[] = $row;
                }
                if (count($result) >= $limit) {
                    return $result;
                }
            }
        }

        return $result;
    }
}
