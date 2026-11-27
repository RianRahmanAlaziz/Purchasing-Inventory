<?php

declare(strict_types=1);

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCompany
{
    public function execute(Company $company): bool
    {
        return DB::transaction(function () use ($company): bool {
            // Ledger writers acquire a shared Company lock before changing stock.
            // No item locks here: deleting a location must not invert item lock order.
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            if (DB::table('inventory_balances')->where('company_id', $locked->id)->where('quantity', '<>', 0)->exists()
                || DB::table('office_assets')->where('company_id', $locked->id)->exists()
                || DB::table('inventory_transfers')->where('status', 'sent')
                    ->where(fn ($query) => $query->where('from_company_id', $locked->id)->orWhere('to_company_id', $locked->id))->exists()) {
                return false;
            }
            User::query()->where('default_company_id', $locked->id)->update(['default_company_id' => null]);

            return (bool) $locked->delete();
        }, attempts: 3);
    }
}
