<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DistributionController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeSelfController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseOrderSuggestionController;
use App\Http\Controllers\Api\V1\RbacAuditController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TaxOptionController;
use App\Http\Controllers\Api\V1\TaxRateController;
use App\Http\Controllers\Api\V1\TaxTypeController;
use App\Http\Controllers\Api\V1\UserController;
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
        Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])->whereNumber('user');
        Route::get('/audit-logs', RbacAuditController::class);
    });

    Route::prefix('users')->name('users.')->group(function (): void {
        Route::get('/', [UserController::class, 'index'])->middleware('can:users.view')->name('index');
        Route::post('/', [UserController::class, 'store'])->middleware(['can:users.manage', 'can:roles.manage'])->name('store');
        Route::patch('/{user}', [UserController::class, 'update'])->whereNumber('user')
            ->middleware(['can:users.manage', 'can:roles.manage'])->name('update');
    });

    Route::get('/employees/me', EmployeeSelfController::class)->name('employees.me');

    Route::prefix('invoices')->name('invoices.')->group(function (): void {
        Route::get('/', [InvoiceController::class, 'index'])->middleware('can:invoices.view')->name('index');
        Route::post('/', [InvoiceController::class, 'store'])->middleware('can:invoices.manage')->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->middleware('can:invoices.view')->name('show');
        Route::patch('/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice')->middleware('can:invoices.manage')->name('update');
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy'])->whereNumber('invoice')->middleware('can:invoices.manage')->name('destroy');
    });

    Route::prefix('payments')->name('payments.')->group(function (): void {
        Route::get('/', [PaymentController::class, 'index'])->middleware('can:payments.view')->name('index');
        Route::post('/', [PaymentController::class, 'store'])->middleware('can:payments.manage')->name('store');
        Route::get('/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->middleware('can:payments.view')->name('show');
        Route::patch('/{payment}', [PaymentController::class, 'update'])->whereNumber('payment')->middleware('can:payments.manage')->name('update');
        Route::delete('/{payment}', [PaymentController::class, 'destroy'])->whereNumber('payment')->middleware('can:payments.manage')->name('destroy');
        Route::post('/{payment}/verify', [PaymentController::class, 'verify'])->whereNumber('payment')->middleware('can:payments.verify')->name('verify');
        Route::post('/{payment}/reject', [PaymentController::class, 'reject'])->whereNumber('payment')->middleware('can:payments.verify')->name('reject');
    });

    Route::prefix('documents')->name('documents.')->group(function (): void {
        Route::get('/', [DocumentController::class, 'index'])->middleware('can:documents.view')->name('index');
        Route::post('/', [DocumentController::class, 'store'])->middleware('can:documents.manage')->name('store');
        Route::get('/{document}', [DocumentController::class, 'show'])->whereNumber('document')->middleware('can:documents.view')->name('show');
        Route::patch('/{document}', [DocumentController::class, 'update'])->whereNumber('document')->middleware('can:documents.manage')->name('update');
        Route::delete('/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->middleware('can:documents.manage')->name('destroy');
        Route::get('/{document}/download', [DocumentController::class, 'download'])->whereNumber('document')->middleware('can:documents.view')->name('download');
        foreach (['send', 'receive', 'verify'] as $action) {
            Route::post('/{document}/'.$action, [DocumentController::class, 'transition'])->whereNumber('document')->defaults('action', $action)
                ->middleware('can:documents.'.$action)->name($action);
        }
    });

    Route::prefix('companies')->name('companies.')->group(function (): void {
        Route::get('/', [CompanyController::class, 'index'])->middleware('can:companies.view')->name('index');
        Route::post('/', [CompanyController::class, 'store'])->middleware('can:companies.manage')->name('store');
        Route::get('/{company}', [CompanyController::class, 'show'])->whereNumber('company')->middleware('can:companies.view')->name('show');
        Route::patch('/{company}', [CompanyController::class, 'update'])->whereNumber('company')->middleware('can:companies.manage')->name('update');
        Route::delete('/{company}', [CompanyController::class, 'destroy'])->whereNumber('company')->middleware('can:companies.manage')->name('destroy');
        Route::post('/{company}/activate', [CompanyController::class, 'activate'])->whereNumber('company')->middleware('can:companies.view')->name('activate');
    });

    Route::prefix('dashboard')->name('dashboard.')
        ->middleware('can:reports.view')
        ->group(function (): void {
            Route::get('/overview', [DashboardController::class, 'overview'])->name('overview');
            Route::get('/activity', [DashboardController::class, 'activity'])->name('activity');
            Route::get('/low-stock', [DashboardController::class, 'lowStock'])->middleware('can:inventory.view')->name('low-stock');
        });

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

    Route::prefix('purchase-orders')->name('purchase-orders.')->group(function (): void {
        Route::get('/suggestions/suppliers', [PurchaseOrderSuggestionController::class, 'suppliers'])
            ->middleware(['can:po.view', 'can:suppliers.view'])->name('suggestions.suppliers');
        Route::get('/suggestions/items', [PurchaseOrderSuggestionController::class, 'items'])
            ->middleware(['can:po.view', 'can:items.view'])->name('suggestions.items');
        Route::get('/suggestions/categories', [PurchaseOrderSuggestionController::class, 'categories'])
            ->middleware(['can:po.view', 'can:items.manage'])->name('suggestions.categories');
        Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('can:po.view')->name('index');
        Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('can:po.create')->name('store');
        Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder')->middleware('can:po.view')->name('show');
        Route::patch('/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder')->middleware('can:po.update')->name('update');
        Route::delete('/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->whereNumber('purchaseOrder')->middleware('can:po.update')->name('destroy');
        Route::post('/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->whereNumber('purchaseOrder')->middleware('can:po.submit')->name('submit');
        Route::post('/{purchaseOrder}/approvals/{step}/approve', [PurchaseOrderController::class, 'approve'])->whereNumber('purchaseOrder')->whereIn('step', ['hr', 'gm'])->name('approvals.approve');
        Route::post('/{purchaseOrder}/approvals/{step}/reject', [PurchaseOrderController::class, 'reject'])->whereNumber('purchaseOrder')->whereIn('step', ['hr', 'gm'])->name('approvals.reject');
        Route::post('/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])->whereNumber('purchaseOrder')->middleware('can:po.send')->name('send');
        Route::post('/{purchaseOrder}/supplier-confirm', [PurchaseOrderController::class, 'supplierConfirm'])->whereNumber('purchaseOrder')->middleware('can:po.supplier-confirm')->name('supplier-confirm');
        Route::post('/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('purchaseOrder')->middleware('can:po.update')->name('cancel');
    });

    Route::prefix('orders')->name('orders.')->middleware('can:orders.view')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/summary', [OrderController::class, 'summary'])->name('summary');
        Route::get('/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('show');
        Route::get('/{order}/receipts', [OrderController::class, 'receipts'])->whereNumber('order')->name('receipts');
    });

    Route::prefix('goods-receipts')->name('goods-receipts.')->group(function (): void {
        Route::get('/', [GoodsReceiptController::class, 'index'])->middleware('can:grn.view')->name('index');
        Route::post('/', [GoodsReceiptController::class, 'store'])->middleware('can:grn.create')->name('store');
        Route::get('/{goodsReceipt}', [GoodsReceiptController::class,  'show'])->whereNumber('goodsReceipt')->middleware('can:grn.view')->name('show');
        Route::patch('/{goodsReceipt}', [GoodsReceiptController::class,  'update'])->whereNumber('goodsReceipt')->middleware('can:grn.create')->name('update');
        Route::post('/{goodsReceipt}/confirm', [GoodsReceiptController::class,   'confirm'])->whereNumber('goodsReceipt')->middleware('can:grn.confirm')->name('confirm');
    });

    Route::prefix('distributions')->name('distributions.')->group(function (): void {
        Route::get('/', [DistributionController::class, 'index'])->name('index');
        Route::post('/', [DistributionController::class, 'store'])->name('store');

        Route::get('/{distribution}', [DistributionController::class, 'show'])->whereNumber('distribution')->name('show');
        Route::post('/{distribution}/approve', [DistributionController::class, 'approve'])->whereNumber('distribution')->middleware('can:distribution.approve')->name('approve');
        Route::post('/{distribution}/reject', [DistributionController::class, 'reject'])->whereNumber('distribution')->middleware('can:distribution.reject')->name('reject');
        Route::post('/{distribution}/prepare', [DistributionController::class, 'prepare'])->whereNumber('distribution')->middleware('can:distribution.prepare')->name('prepare');
        Route::post('/{distribution}/send', [DistributionController::class, 'send'])->whereNumber('distribution')->middleware('can:distribution.send')->name('send');
        // Self-service: tanpa permission operasional, identitas penerima wajib cocok di Action.
        Route::post('/{distribution}/receive', [DistributionController::class, 'receive'])->whereNumber('distribution')->name('receive');
    });
});
