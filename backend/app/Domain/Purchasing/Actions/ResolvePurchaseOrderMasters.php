<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Item\Actions\SaveItem;
use App\Domain\Item\Models\Item;
use App\Domain\Item\Policies\ItemPolicy;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Supplier\Actions\SaveSupplier;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Support\SupplierName;
use App\Models\User;
use App\Support\ShortReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResolvePurchaseOrderMasters
{
    public function requester(PurchaseOrder $po, array $data): array
    {
        $employeeName = isset($data['requested_for_employee_name']) ? Str::squish($data['requested_for_employee_name']) : null;
        $departmentName = isset($data['requested_for_department_name']) ? Str::squish($data['requested_for_department_name']) : null;
        $employeeId = $employeeName !== null ? null : ($data['requested_for_employee_id'] ?? $po->requested_for_employee_id);
        $departmentId = $departmentName !== null ? null : ($data['requested_for_department_id'] ?? $po->requested_for_department_id);

        $employee = $employeeId === null ? null : Employee::query()->lockForUpdate()->findOrFail($employeeId);
        $department = $departmentId === null ? null : Department::query()->lockForUpdate()->findOrFail($departmentId);
        if (isset($data['requested_for_employee_id']) && ! $employee?->is_active) {
            throw ValidationException::withMessages(['requested_for_employee_id' => ['Karyawan harus aktif.']]);
        }
        if (isset($data['requested_for_department_id']) && ! $department?->is_active) {
            throw ValidationException::withMessages(['requested_for_department_id' => ['Departemen harus aktif.']]);
        }
        if ($employee !== null && $department !== null
            && (isset($data['requested_for_employee_id']) || isset($data['requested_for_department_id']))
            && (int) $employee->department_id !== (int) $department->id) {
            throw ValidationException::withMessages(['requested_for_department_id' => ['Departemen harus sesuai departemen karyawan.']]);
        }

        $employeeName ??= isset($data['requested_for_employee_id']) ? $employee?->name
            : ($po->requested_for_employee_name_snapshot ?? $employee?->name);
        $departmentName ??= isset($data['requested_for_department_id']) ? $department?->name
            : ($po->requested_for_department_name_snapshot ?? $department?->name);
        if ($employeeName === null || $employeeName === '') {
            throw ValidationException::withMessages(['requested_for_employee_name' => ['Isi nama karyawan pemohon.']]);
        }
        if ($departmentName === null || $departmentName === '') {
            throw ValidationException::withMessages(['requested_for_department_name' => ['Isi nama departemen pemohon.']]);
        }

        return [
            'requested_for_employee_id' => $employeeId,
            'requested_for_employee_name_snapshot' => $employeeName,
            'requested_for_department_id' => $departmentId,
            'requested_for_department_name_snapshot' => $departmentName,
        ];
    }

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
        if (! $actor->can('items.manage') && ! ItemPolicy::canCreate($actor)) {
            throw new AuthorizationException('Barang baru memerlukan izin mengelola barang. Pilih sugesti stok yang tersedia.');
        }
        if ($categoryId === null) {
            throw ValidationException::withMessages(["items.$index.category_id" => ['Pilih kategori untuk barang baru.']]);
        }

        $item = app(SaveItem::class)->create(['name' => $name, 'unit' => $unit, 'category_id' => $categoryId,
            'sku' => 'ITEM-'.Str::ulid(), 'minimum_stock' => '0', 'is_active' => true], awaitingFirstReceipt: true);
        ShortReference::assign($item, 'sku', 'ITEM');

        return $item;
    }
}
