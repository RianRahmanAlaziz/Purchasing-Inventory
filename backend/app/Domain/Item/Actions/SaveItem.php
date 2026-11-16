<?php

declare(strict_types=1);

namespace App\Domain\Item\Actions;

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
        'category_id', 'sku', 'name', 'unit', 'minimum_stock', 'maximum_stock', 'reference_price', 'is_active',
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
