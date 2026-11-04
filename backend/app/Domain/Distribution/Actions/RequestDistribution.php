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
            $employee = Employee::query()->findOrFail($data['employee_id']);

            if (! $employee->is_active) {
                throw new DistributionConflict('Karyawan penerima harus aktif.');
            }

            if (! $actor->can('distribution.create')) {
                if ((int) $employee->user_id !== (int) $actor->id
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

            $active = Item::query()->whereIn('id', $ids)
                ->where('is_active', true)->count();
            if ($active !== count($ids)) {
                throw new DistributionConflict('Seluruh barang yang diajukan harus aktif.');
            }

            $distribution = Distribution::query()->create([
                'distribution_number' => 'DIST-'.(string) Str::ulid(),
                'employee_id' => $employee->id,
                'requested_by' => $actor->id,
                'purpose' => $data['purpose'],
                'status' => DistributionStatus::Requested->value,
                'requested_at' => now(),
            ]);

            foreach ($lines as $line) {
                try {
                    $qty = StockDecimal::parse((string) $line['quantity']);
                } catch (InvalidArgumentException) {
                    throw new DistributionConflict('Format kuantitas distribusi tidak valid.');
                }

                if ($qty <= 0) {
                    throw new DistributionConflict('Kuantitas distribusi harus lebih dari nol.');
                }

                $distribution->items()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => StockDecimal::format($qty),
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $distribution->events()->create([
                'actor_id' => $actor->id,
                'action' => 'requested',
                'from_status' => null,
                'to_status' => DistributionStatus::Requested->value,
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
