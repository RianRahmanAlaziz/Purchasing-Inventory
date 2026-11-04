<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeSelfController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RbacAuditController;
use App\Http\Controllers\Api\V1\RoleController;
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
        Route::get('/', [EmployeeController::class, 'index'])
            ->middleware('can:employees.view')->name('index');

        Route::post('/', [EmployeeController::class, 'store'])
            ->middleware('can:employees.manage')->name('store');

        Route::get('/{employee}', [EmployeeController::class, 'show'])
            ->whereNumber('employee')
            ->middleware('can:employees.view')->name('show');

        Route::patch('/{employee}', [EmployeeController::class, 'update'])
            ->whereNumber('employee')
            ->middleware('can:employees.manage')->name('update');

        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])
            ->whereNumber('employee')
            ->middleware('can:employees.manage')->name('destroy');
    });
});
