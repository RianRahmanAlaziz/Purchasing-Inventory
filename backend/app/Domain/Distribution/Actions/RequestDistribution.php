<?php

declare(strict_types=1);

namespace App\Domain\Distribution\Actions;

use App\Domain\Distribution\Enums\DistributionStatus;
use App\Domain\Distribution\Exceptions\DistributionConflict;
use App\Domain\Distribution\Models\Distribution;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use App\Models\User;
use App\Support\ShortReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RequestDistribution
{
    /** @param array<string, mixed> $data Data harus berasal dari StoreDistributionRequest::validated(). */
    public function execute(array $data, User $actor): Distribution
    {
        return DB::transaction(function () use ($data, $actor): Distribution {
            $employee = isset($data['employee_id'])
                ? Employee::query()->findOrFail($data['employee_id']) : null;
            $recipientName = trim((string) ($data['recipient_name'] ?? $employee?->name));

            if ($employee !== null && ! $employee->is_active) {
                throw new DistributionConflict('Karyawan penerima harus aktif.');
            }
            if ($recipientName === '') {
                throw new DistributionConflict('Nama penerima wajib diisi.');
            }

            if (! $actor->can('distribution.create')) {
                if ($employee === null || isset($data['recipient_name'])
                    || (int) $employee->user_id !== (int) $actor->id
                    || ! $actor->tokenCan('employee:self')) {
                    throw new AuthorizationException('Hanya boleh meminta distribusi untuk diri sendiri.');
                }
            }

            $lines = $data['items'];
            if (! is_array($lines) || count($lines) === 0) {
                throw new DistributionConflict('Distribusi minimal memiliki satu item.');
            }

            $ids = array_map(static fn (array $line): int => (int) $line['item_id'], $lines);
            if (count(array_unique($ids)) !== count($ids)) {
                throw new DistributionConflict('Item tidak boleh berulang dalam distribusi yang sama.');
            }

            $active = Item::query()->inInventory()->whereIn('id', $ids)
                ->where('is_active', true)->count();
            if ($active !== count($ids)) {
                throw new DistributionConflict('Seluruh barang yang diajukan harus aktif dan sudah masuk Stok Barang.');
            }

            $distribution = Distribution::query()->create([
                'distribution_number' => 'DIST-'.(string) Str::ulid(),
                'employee_id' => $employee?->id,
                'recipient_name_snapshot' => $recipientName,
                'requested_by' => $actor->id,
                'company_id' => $data['company_id'],
                'purpose' => $data['purpose'],
                'status' => DistributionStatus::Preparing->value,
                'requested_at' => now(),
            ]);
            ShortReference::assign($distribution, 'distribution_number', 'DIST');

            foreach ($lines as $line) {
                try {
                    $qty = StockDecimal::parse((string) $line['quantity']);
                } catch (InvalidArgumentException) {
                    throw new DistributionConflict('Format kuantitas distribusi tidak valid.');
                }

                if ($qty <= 0) {
                    throw new DistributionConflict('Kuantitas distribusi harus lebih dari nol.');
                }

                $item = Item::query()->findOrFail($line['item_id']);
                $assetIds = $line['asset_ids'] ?? [];
                if (($item->item_type === 'office_asset' && ($qty % 1000 !== 0 || count($assetIds) * 1000 !== $qty))
                    || ($item->item_type !== 'office_asset' && $assetIds !== [])) {
                    throw new DistributionConflict('Pilih kode aset tepat sejumlah unit utuh, hanya untuk barang jenis aset kantor.');
                }

                $distribution->items()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => StockDecimal::format($qty),
                    'notes' => $line['notes'] ?? null,
                    'asset_ids' => $line['asset_ids'] ?? [],
                ]);
            }

            $distribution->events()->create([
                'actor_id' => $actor->id,
                'action' => 'preparing',
                'from_status' => null,
                'to_status' => DistributionStatus::Preparing->value,
                'created_at' => now(),
            ]);

            return $this->loaded($distribution);
        }, attempts: 3);
    }

    private function loaded(Distribution $distribution): Distribution
    {
        return $distribution->load(['employee.department', 'items.item', 'items.movement', 'events']);
    }
}
