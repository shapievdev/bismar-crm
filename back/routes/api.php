<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->as('auth.')->group(function (): void {
    Route::post('register', [RegisteredUserController::class, 'store'])->name('register');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('user', [AuthenticatedUserController::class, 'show'])->name('user');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('permissions', [PermissionController::class, 'index'])
        ->middleware('can:'.Permission::ManageRoles->value)
        ->name('permissions.index');

    Route::apiResource('roles', RoleController::class)
        ->except('show')
        ->middleware('can:'.Permission::ManageRoles->value);

    Route::get('users', [UserController::class, 'index'])
        ->middleware('can:'.Permission::ViewUsers->value)
        ->name('users.index');

    Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])
        ->middleware('can:'.Permission::ManageUsers->value)
        ->name('users.roles.update');
});
