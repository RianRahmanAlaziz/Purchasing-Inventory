<?php

declare(strict_types=1);

use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Transfer\Actions\TransferWorkflow;
use App\Domain\Transfer\Models\InventoryTransfer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::getDefaultConnection() !== 'mysql'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'purchasing_test') {
    throw new RuntimeException('Concurrent transfer worker is restricted to purchasing_test.');
}

[$script, $transferId, $actorId, $startAt] = $argv;
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $transfer = app(TransferWorkflow::class)->send(
        InventoryTransfer::query()->findOrFail((int) $transferId),
        User::query()->findOrFail((int) $actorId),
    );
    echo json_encode(['sent' => true, 'status' => $transfer->status], JSON_THROW_ON_ERROR);
} catch (StockConflictException $exception) {
    echo json_encode(['sent' => false, 'conflict' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
