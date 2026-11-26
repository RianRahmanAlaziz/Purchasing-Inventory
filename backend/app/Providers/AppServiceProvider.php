<?php

namespace App\Providers;

use App\Domain\Maintenance\Models\MaintenanceAsset;
use App\Domain\Maintenance\Policies\MaintenanceAssetPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(
            MaintenanceAsset::class,
            MaintenanceAssetPolicy::class,
        );
    }
}
