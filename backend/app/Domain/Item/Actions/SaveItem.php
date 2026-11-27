<?php

declare(strict_types=1);

namespace App\Domain\Item\Actions;

use App\Domain\Asset\Actions\AssetWorkflow;
use App\Domain\Category\Models\Category;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveItem
{
    private const EDITABLE = [
        'item_type', 'category_id', 'sku', 'name', 'unit', 'minimum_stock', 'maximum_stock', 'reference_price', 'is_active',
    ];

    public function create(array $data, bool $awaitingFirstReceipt = false): Item
    {
        $data = Arr::only($data, self::EDITABLE);
        $data['is_active'] ??= true;
        $data['minimum_stock'] ??= '0';
        $this->assertStockLimits((string) $data['minimum_stock'], isset($data['maximum_stock']) ? (string) $data['maximum_stock'] : null);

        try {
            return DB::transaction(function () use ($data, $awaitingFirstReceipt): Item {
                $this->assertActiveCategory((int) $data['category_id']);

                $item = new Item($data);
                $item->awaiting_first_receipt = $awaitingFirstReceipt;
                $item->save();

                return $this->fullItem($item->getKey());
            }, attempts: 3);
        } catch (QueryException $exception) {
            $this->rejectDuplicateSku($exception);
            throw $exception;
        }
    }

    public function update(Item $item, array $data): Item
    {
        $data = Arr::only($data, self::EDITABLE);

        try {
            return DB::transaction(function () use ($item, $data): Item {
                $locked = Item::query()->lockForUpdate()
                    ->findOrFail($item->getKey());
                if (isset($data['item_type']) && $data['item_type'] !== $locked->item_type) {
                    if (DB::table('office_assets')->where('item_id', $locked->id)->exists()) {
                        throw ValidationException::withMessages(['item_type' => ['Barang yang telah memiliki identitas aset tidak dapat diubah jenisnya.']]);
                    }
                    if (DB::table('inventory_transfer_items as l')->join('inventory_transfers as t', 't.id', '=', 'l.inventory_transfer_id')
                        ->where('l.item_id', $locked->id)->where('t.status', 'sent')->exists()) {
                        throw ValidationException::withMessages(['item_type' => ['Selesaikan transfer dalam perjalanan sebelum mengubah jenis barang.']]);
                    }
                    if (DB::table('distribution_items as l')->join('distributions as d', 'd.id', '=', 'l.distribution_id')
                        ->where('l.item_id', $locked->id)->whereIn('d.status', ['requested', 'approved', 'preparing'])->exists()) {
                        throw ValidationException::withMessages(['item_type' => ['Selesaikan distribusi yang belum dikirim sebelum mengubah jenis barang.']]);
                    }
                    if ($data['item_type'] === 'office_asset') {
                        $fractionalOrders = DB::table('purchase_order_items as l')->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
                            ->where('l.item_id', $locked->id)->whereNull('p.deleted_at')
                            ->whereIn('p.status', ['draft', 'pending_approval', 'approved', 'sent', 'received_by_supplier'])
                            ->whereRaw('l.quantity <> FLOOR(l.quantity)')
                            ->whereNotExists(function ($q): void {
                                $q->selectRaw('1')->from('orders as o')->whereColumn('o.purchase_order_id', 'p.id')->where('o.status', 'completed');
                            })->exists();
                        $fractionalReceipts = DB::table('goods_receipt_items as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
                            ->join('purchase_order_items as p', 'p.id', '=', 'l.purchase_order_item_id')
                            ->where('p.item_id', $locked->id)->whereNull('g.confirmed_at')
                            ->whereRaw('(l.qty_received <> FLOOR(l.qty_received) OR l.qty_damaged <> FLOOR(l.qty_damaged))')->exists();
                        if ($fractionalOrders || $fractionalReceipts) {
                            throw ValidationException::withMessages(['item_type' => ['Selesaikan atau koreksi jumlah pecahan pada PO/penerimaan aktif sebelum mengubah barang menjadi aset.']]);
                        }
                        $locked->item_type = 'office_asset';
                        $balances = DB::table('inventory_balances')->where('item_id', $locked->id)->get();
                        foreach ($balances as $balance) {
                            $units = StockDecimal::parse((string) $balance->quantity);
                            if ($units !== 0 && ($balance->company_id === null || $units % 1000 !== 0)) {
                                throw ValidationException::withMessages(['item_type' => ['Alokasikan seluruh stok lama ke PT dan pastikan jumlah unit utuh sebelum menjadi aset.']]);
                            }
                            if ($units > 0) {
                                app(AssetWorkflow::class)->registerUnits($locked, (int) $balance->company_id, intdiv($units, 1000), (int) auth()->id(), ['source_type' => 'classification-company-'.$balance->company_id, 'source_id' => $locked->id]);
                            }
                        }
                    }
                }
                $maximum = array_key_exists('maximum_stock', $data) ? $data['maximum_stock'] : $locked->maximum_stock;
                $this->assertStockLimits(
                    (string) ($data['minimum_stock'] ?? $locked->minimum_stock),
                    $maximum === null ? null : (string) $maximum,
                );

                if (array_key_exists('category_id', $data)) {
                    $this->assertActiveCategory((int) $data['category_id']);
                } elseif (array_key_exists('is_active', $data)
                    && filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN)
                    && ! $locked->is_active) {
                    // Reaktivasi tidak boleh dalam kategori yang nonaktif.
                    $this->assertActiveCategory((int) $locked->category_id);
                }

                $locked->update($data);

                return $this->fullItem($locked->getKey());
            }, attempts: 3);
        } catch (QueryException $exception) {
            $this->rejectDuplicateSku($exception);
            throw $exception;
        }
    }

    private function assertActiveCategory(int $categoryId): void
    {
        $category = Category::query()->lockForUpdate()->find($categoryId);

        if ($category === null || ! $category->is_active) {
            throw ValidationException::withMessages([
                'category_id' => ['Kategori harus tersedia dan aktif.'],
            ]);
        }
    }

    private function assertStockLimits(string $minimum, ?string $maximum): void
    {
        if ($maximum === null) {
            return;
        }

        $min = StockDecimal::parse(preg_replace('/^0+(?=[0-9])/', '', $minimum));
        $max = StockDecimal::parse(preg_replace('/^0+(?=[0-9])/', '', $maximum));
        if ($max < $min) {
            throw ValidationException::withMessages(['maximum_stock' => ['Stok maksimum harus sama dengan atau lebih besar dari stok minimum.']]);
        }
    }

    private function fullItem(int|string $id): Item
    {
        return Item::query()->withStockAlert()
            ->with('category:id,code,name,is_active')
            ->findOrFail($id);
    }

    private function rejectDuplicateSku(QueryException $exception): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw ValidationException::withMessages([
                'sku' => ['SKU barang sudah digunakan.'],
            ]);
        }
    }
}
