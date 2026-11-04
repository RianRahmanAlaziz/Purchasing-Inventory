<?php

declare(strict_types=1);

namespace App\Domain\Item\Actions;

use App\Domain\Category\Models\Category;
use App\Domain\Item\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveItem
{
    private const EDITABLE = [
        'category_id', 'sku', 'name', 'unit', 'minimum_stock', 'is_active',
    ];

    public function create(array $data): Item
    {
        $data = Arr::only($data, self::EDITABLE);
        $data['is_active'] ??= true;
        $data['minimum_stock'] ??= '0';

        try {
            return DB::transaction(function () use ($data): Item {
                $this->assertActiveCategory((int) $data['category_id']);

                $item = Item::query()->create($data);

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
