<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Policies;

use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Models\User;

final class MaintenanceAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('maintenance.assets.view')
            && $user->can(
                'maintenance.assets.view-all-companies'
            );
    }

    public function view(
        User $user,
        MaintenanceAsset $asset
    ): bool {
        return $this->viewAny($user);
    }

    public function update(
        User $user,
        MaintenanceAsset $asset
    ): bool {
        return $user->can('maintenance.assets.manage')
            && $user->can(
                'maintenance.assets.manage-all-companies'
            );
    }

    public function create(User $user): bool
    {
        // Hanya melalui proses registrasi khusus.
        return false;
    }

    public function delete(
        User $user,
        MaintenanceAsset $asset
    ): bool {
        return false;
    }
}
