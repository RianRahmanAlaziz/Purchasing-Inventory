<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Support\SupplierName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SaveSupplier
{
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data): Supplier {
            $data['code'] ??= 'SUP-'.(string) Str::ulid();
            $data['normalized_name'] = SupplierName::normalized($data['name']);

            return Supplier::query()->create($data);
        }, attempts: 3);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data): Supplier {
            $locked = Supplier::query()
                ->lockForUpdate()
                ->findOrFail($supplier->getKey());

            // Jangan pernah menerima normalized_name yang dibuat client.
            unset($data['normalized_name']);
            if (array_key_exists('name', $data)) {
                $data['normalized_name'] = SupplierName::normalized($data['name']);
            }

            $locked->update($data);

            return $locked->refresh();
        }, attempts: 3);
    }
}
