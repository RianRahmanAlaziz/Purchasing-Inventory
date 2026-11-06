<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Category\Models\Category;
use App\Domain\Item\Actions\SaveItem;
use App\Domain\Item\Models\Item;
use App\Domain\Supplier\Actions\SaveSupplier;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Support\SupplierName;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResolvePurchaseOrderMasters
{
    public function supplier(array $data, User $actor): Supplier
    {
        if (! empty($data['supplier_id'])) {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($data['supplier_id']);
        } else {
            $name = SupplierName::display((string) ($data['supplier_name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages(['supplier_name' => ['Isi nama supplier.']]);
            }
            $normalized = SupplierName::normalized($name);
            $supplier = Supplier::query()->where('normalized_name', $normalized)->lockForUpdate()->first();
            if ($supplier === null) {
                if (! $actor->can('suppliers.create')) {
                    throw new AuthorizationException('Nama supplier baru memerlukan izin menambah supplier.');
                }
                try {
                    $supplier = app(SaveSupplier::class)->create(['name' => $name, 'address' => $data['supplier_address'] ?? null, 'is_active' => true]);
                } catch (UniqueConstraintViolationException $exception) {
                    $supplier = Supplier::query()->where('normalized_name', $normalized)->lockForUpdate()->first();
                    if ($supplier === null) {
                        throw $exception;
                    }
                }
            }
        }
        if (! $supplier->is_active) {
            $field = empty($data['supplier_id']) ? 'supplier_name' : 'supplier_id';
            throw ValidationException::withMessages([$field => ['Supplier tidak aktif. Pilih supplier aktif atau aktifkan kembali melalui master supplier.']]);
        }

        return $supplier;
    }

    public function item(array $entry, User $actor, int $index): Item
    {
        if (! empty($entry['item_id'])) {
            return Item::query()->with('category')->lockForUpdate()->findOrFail($entry['item_id']);
        }
        $name = Str::squish((string) ($entry['name'] ?? ''));
        $unit = Str::lower(trim((string) ($entry['unit'] ?? '')));
        if ($name === '' || $unit === '') {
            throw ValidationException::withMessages(["items.$index.name" => ['Isi nama dan satuan barang.']]);
        }
        $categoryId = $entry['category_id'] ?? null;
        if ($categoryId !== null) {
            $category = Category::query()->lockForUpdate()->find($categoryId);
            if ($category === null || ! $category->is_active) {
                throw ValidationException::withMessages(["items.$index.category_id" => ['Kategori barang harus aktif.']]);
            }
        }
        $matches = Item::query()->with('category')->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])->where('unit', $unit)
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->lockForUpdate()->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(["items.$index.name" => ['Ada beberapa barang bernama sama. Pilih sugesti berdasarkan SKU yang sesuai.']]);
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if (! $actor->can('items.manage')) {
            throw new AuthorizationException('Barang baru memerlukan izin mengelola barang. Pilih sugesti stok yang tersedia.');
        }
        if ($categoryId === null) {
            throw ValidationException::withMessages(["items.$index.category_id" => ['Pilih kategori untuk barang baru.']]);
        }

        return app(SaveItem::class)->create(['name' => $name, 'unit' => $unit, 'category_id' => $categoryId,
            'sku' => 'ITEM-'.Str::ulid(), 'minimum_stock' => '0', 'is_active' => true]);
    }
}
