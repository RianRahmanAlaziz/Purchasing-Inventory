<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DistributionController;
use App\Http\Controllers\Api\V1\InventoryTransferController;
use App\Http\Controllers\Api\V1\OfficeAssetController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;

// Loaded inside the existing authenticated /api/v1 group.
Route::post('/distributions/{distribution}/location', [DistributionController::class, 'location'])
    ->whereNumber('distribution')->middleware('can:distribution.send');
Route::get('/inventory/asset-options', [OfficeAssetController::class, 'options'])
    ->middleware(PermissionMiddleware::using('distribution.create|inventory.transfer.create|assets.view|assets.manage'));

Route::prefix('inventory-transfers')->name('inventory-transfers.')->group(function (): void {
    Route::get('/', [InventoryTransferController::class, 'index'])->middleware('can:inventory.transfer.view')->name('index');
    Route::post('/', [InventoryTransferController::class, 'store'])->middleware('can:inventory.transfer.create')->name('store');
    Route::get('/{inventoryTransfer}', [InventoryTransferController::class, 'show'])->whereNumber('inventoryTransfer')->middleware('can:inventory.transfer.view')->name('show');
    Route::patch('/{inventoryTransfer}', [InventoryTransferController::class, 'update'])->whereNumber('inventoryTransfer')->middleware('can:inventory.transfer.create')->name('update');
    Route::post('/{inventoryTransfer}/send', [InventoryTransferController::class, 'send'])->whereNumber('inventoryTransfer')->middleware('can:inventory.transfer.send')->name('send');
    Route::post('/{inventoryTransfer}/receive', [InventoryTransferController::class, 'receive'])->whereNumber('inventoryTransfer')->middleware('can:inventory.transfer.receive')->name('receive');
    Route::post('/{inventoryTransfer}/cancel', [InventoryTransferController::class, 'cancel'])->whereNumber('inventoryTransfer')->middleware('can:inventory.transfer.create')->name('cancel');
});

Route::prefix('assets')->name('assets.')->group(function (): void {
    Route::get('/', [OfficeAssetController::class, 'index'])->middleware('can:assets.view')->name('index');
    Route::get('/{officeAsset}', [OfficeAssetController::class, 'show'])->whereNumber('officeAsset')->middleware('can:assets.view')->name('show');
    Route::post('/{officeAsset}/assign', [OfficeAssetController::class, 'assign'])->whereNumber('officeAsset')->middleware('can:assets.manage')->name('assign');
    Route::post('/{officeAsset}/reassign', [OfficeAssetController::class, 'reassign'])->whereNumber('officeAsset')->middleware('can:assets.manage')->name('reassign');
    Route::post('/{officeAsset}/return', [OfficeAssetController::class, 'returnAsset'])->whereNumber('officeAsset')->middleware('can:assets.manage')->name('return');
    Route::post('/{officeAsset}/condition', [OfficeAssetController::class, 'changeCondition'])->whereNumber('officeAsset')->middleware('can:assets.manage')->name('condition');
});
