<?php

declare(strict_types=1);

namespace App\Domain\Distribution\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Distribution\Exceptions\DistributionConflict;
use App\Domain\Distribution\Models\Distribution;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SelectDistributionCompany
{
    public function execute(Distribution $distribution, int $companyId, User $actor): Distribution
    {
        if (! $actor->can('distribution.send')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($distribution, $companyId, $actor): Distribution {
            $locked = Distribution::query()->lockForUpdate()->findOrFail($distribution->id);
            if (! in_array($locked->status, ['requested', 'approved', 'preparing'], true)
                || $locked->sent_at !== null
                || StockMovement::query()->whereIn('distribution_item_id', $locked->items()->select('id'))->exists()) {
                throw new DistributionConflict('PT asal hanya dapat dipilih sebelum stok distribusi keluar.');
            }
            if (Company::query()->whereKey($companyId)->sharedLock()->first() === null) {
                throw new DistributionConflict('PT asal tidak tersedia.');
            }
            if ($locked->company_id !== null) {
                if ((int) $locked->company_id !== $companyId) {
                    throw new DistributionConflict('PT asal dokumen sudah ditetapkan.');
                }

                return $locked;
            }
            $locked->update(['company_id' => $companyId]);
            $locked->events()->create([
                'actor_id' => $actor->id, 'action' => 'location_selected',
                'from_status' => $locked->status, 'to_status' => $locked->status,
                'notes' => 'PT asal dipilih: '.$companyId, 'created_at' => now(),
            ]);

            return $locked;
        }, attempts: 3);
    }
}
