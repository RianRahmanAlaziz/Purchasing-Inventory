<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmployeeSelfController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', HealthController::class)
    ->name('health');

// Authentication
Route::prefix('auth')->group(function (): void {
    Route::post('/login', [
        AuthController::class,
        'login',
    ])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::middleware([
        'auth:sanctum',
        EnsureActiveAccount::class,
    ])->group(function (): void {
        Route::get('/me', [
            AuthController::class,
            'me',
        ])->name('auth.me');

        Route::post('/logout', [
            AuthController::class,
            'logout',
        ])->name('auth.logout');
    });
});

// Protected API
Route::middleware([
    'auth:sanctum',
    EnsureActiveAccount::class,
])->group(function (): void {

    // Role & Permission Management
    Route::prefix('authorization')
        ->middleware('can:roles.manage')
        ->group(function (): void {
            Route::get('/roles', [
                RoleController::class,
                'index',
            ]);

            Route::post('/roles', [
                RoleController::class,
                'store',
            ]);

            Route::patch('/roles/{role}', [
                RoleController::class,
                'update',
            ]);

            Route::get('/permissions', [
                PermissionController::class,
                'index',
            ]);

            Route::post('/permissions', [
                PermissionController::class,
                'store',
            ]);

            Route::put('/users/{user}/roles', [
                UserRoleController::class,
                'update',
            ]);
        });

    // Employee self-service
    Route::get('/employees/me',  EmployeeSelfController::class)->name('employees.me');
});
