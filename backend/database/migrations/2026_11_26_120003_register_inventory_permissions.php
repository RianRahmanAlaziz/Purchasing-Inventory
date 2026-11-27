<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\ModulePermissionRegistrar;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ModulePermissionRegistrar::class)->register([
            'inventory.transfer.view', 'inventory.transfer.create', 'inventory.transfer.send',
            'inventory.transfer.receive', 'inventory.reconcile', 'assets.view', 'assets.manage',
        ]);
    }

    public function down(): void
    {
        // Keep permissions and administrator-managed grants for forward recovery.
    }
};
