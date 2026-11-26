<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Policies;

use App\Domain\Maintenance\Models\MaintenanceVehicleProfile;
use App\Models\User;

final class MaintenanceVehicleProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('maintenance.vehicles.view')
            && $user->can(
                'maintenance.vehicles.view-all-companies'
            );
    }

    public function view(
        User $user,
        MaintenanceVehicleProfile $profile
    ): bool {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('maintenance.vehicles.manage')
            && $user->can(
                'maintenance.vehicles.manage-all-companies'
            );
    }

    public function update(
        User $user,
        MaintenanceVehicleProfile $profile
    ): bool {
        return $this->create($user);
    }

    public function delete(
        User $user,
        MaintenanceVehicleProfile $profile
    ): bool {
        return false;
    }
}
