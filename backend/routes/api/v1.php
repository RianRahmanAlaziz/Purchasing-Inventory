<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeSelfController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RbacAuditController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TaxOptionController;
use App\Http\Controllers\Api\V1\TaxRateController;
use App\Http\Controllers\Api\V1\TaxTypeController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureActiveAccount::class])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

Route::middleware(['auth:sanctum', EnsureActiveAccount::class])->group(function (): void {
    Route::prefix('authorization')->middleware('can:roles.manage')->group(function (): void {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::post('/permissions', [PermissionController::class, 'store']);
        Route::patch('/permissions/{permission}', [PermissionController::class, 'update']);
        Route::put('/users/{user}/roles', [UserRoleController::class, 'update']);
        Route::get('/audit-logs', RbacAuditController::class);
    });

    Route::get('/employees/me', EmployeeSelfController::class)->name('employees.me');

    Route::prefix('departments')->name('departments.')->group(function (): void {
        Route::get('/', [DepartmentController::class, 'index'])->middleware('can:departments.view')->name('index');
        Route::get('/{department}', [DepartmentController::class,  'show'])->middleware('can:departments.view')->name('show');
        Route::post('/', [DepartmentController::class, 'store'])->middleware('can:departments.manage')->name('store');
        Route::patch('/{department}', [DepartmentController::class, 'update'])->middleware('can:departments.manage')->name('update');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])->middleware('can:departments.manage')->name('destroy');
    });

    Route::prefix('employees')->name('employees.')->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index'])->middleware('can:employees.view')->name('index');
        Route::post('/', [EmployeeController::class, 'store'])->middleware('can:employees.manage')->name('store');
        Route::get('/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->middleware('can:employees.view')->name('show');
        Route::patch('/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->middleware('can:employees.manage')->name('update');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee')->middleware('can:employees.manage')->name('destroy');
    });

    Route::prefix('suppliers')->name('suppliers.')->group(function (): void {
        Route::get('/suggest', [SupplierController::class, 'suggest'])->middleware('can:suppliers.view')->name('suggest');
        Route::get('/', [SupplierController::class,  'index'])->middleware('can:suppliers.view')->name('index');
        Route::post('/', [SupplierController::class, 'store'])->middleware('can:suppliers.create')->name('store');
        Route::get('/{supplier}/purchase-orders', [SupplierController::class,  'purchaseOrders'])->whereNumber('supplier')->middleware(['can:suppliers.view', 'can:po.view'])->name('purchase-orders');
        Route::get('/{supplier}', [SupplierController::class,  'show'])->whereNumber('supplier')->middleware('can:suppliers.view')->name('show');
        Route::patch('/{supplier}', [SupplierController::class,  'update'])->whereNumber('supplier')->middleware('can:suppliers.update')->name('update');
        Route::delete('/{supplier}', [SupplierController::class,  'destroy'])->whereNumber('supplier')->middleware('can:suppliers.delete')->name('destroy');
    });

    Route::prefix('categories')->name('categories.')->group(function (): void {
        Route::get('/', [CategoryController::class,  'index'])->middleware('can:categories.view')->name('index');
        Route::post('/', [CategoryController::class,  'store'])->middleware('can:categories.manage')->name('store');
        Route::get('/{category}', [CategoryController::class,   'show'])->whereNumber('category')->middleware('can:categories.view')->name('show');
        Route::patch('/{category}', [CategoryController::class, 'update'])->whereNumber('category')->middleware('can:categories.manage')->name('update');
        Route::delete('/{category}', [CategoryController::class, 'destroy'])->whereNumber('category')->middleware('can:categories.manage')->name('destroy');
    });

    Route::prefix('items')->name('items.')->group(function (): void {
        Route::get('/', [ItemController::class, 'index'])->middleware('can:items.view')->name('index');
        Route::post('/', [ItemController::class,  'store'])->middleware('can:items.manage')->name('store');
        Route::get('/{item}', [ItemController::class, 'show'])->whereNumber('item')->middleware('can:items.view')->name('show');
        Route::patch('/{item}', [ItemController::class, 'update'])->whereNumber('item')->middleware('can:items.manage')->name('update');
        Route::delete('/{item}', [ItemController::class, 'destroy'])->whereNumber('item')->middleware('can:items.manage')->name('destroy');
    });

    Route::post('/items/{item}/stock-adjustments', [StockAdjustmentController::class, 'store'])->whereNumber('item')->middleware('can:inventory.adjust')->name('inventory.adjustments.store');
    Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware('can:inventory.view')->name('inventory.adjustments.index');
    Route::get('/stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->whereNumber('stockAdjustment')->middleware('can:inventory.view')->name('inventory.adjustments.show');
    Route::get('/stock-movements', [StockMovementController::class, 'index'])->middleware('can:inventory.view')->name('inventory.movements.index');

    Route::get('/taxes/options', TaxOptionController::class)->middleware('can:taxes.view')->name('taxes.options');

    Route::prefix('tax-types')->name('tax-types.')->group(function (): void {
        Route::get('/', [TaxTypeController::class, 'index'])->middleware('can:taxes.view')->name('index');
        Route::post('/', [TaxTypeController::class, 'store'])->middleware('can:taxes.manage')->name('store');
        Route::get('/{taxType}', [TaxTypeController::class, 'show'])->whereNumber('taxType')->middleware('can:taxes.view')->name('show');
        Route::patch('/{taxType}', [TaxTypeController::class, 'update'])->whereNumber('taxType')->middleware('can:taxes.manage')->name('update');
        Route::delete('/{taxType}', [TaxTypeController::class, 'destroy'])->whereNumber('taxType')->middleware('can:taxes.manage')->name('destroy');
    });

    Route::prefix('tax-rates')->name('tax-rates.')->group(function (): void {
        Route::get('/', [TaxRateController::class, 'index'])->middleware('can:taxes.view')->name('index');
        Route::post('/', [TaxRateController::class, 'store'])->middleware('can:taxes.manage')->name('store');
        Route::get('/{taxRate}', [TaxRateController::class, 'show'])->whereNumber('taxRate')->middleware('can:taxes.view')->name('show');
        Route::patch('/{taxRate}', [TaxRateController::class, 'update'])->whereNumber('taxRate')->middleware('can:taxes.manage')->name('update');
        Route::delete('/{taxRate}', [TaxRateController::class, 'destroy'])->whereNumber('taxRate')->middleware('can:taxes.manage')->name('destroy');
    });
});
