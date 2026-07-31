<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\Knowledge\ArticleController;
use App\Http\Controllers\Api\Knowledge\CategoryController;
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

Route::middleware('auth:sanctum')
    ->prefix('knowledge')
    ->as('knowledge.')
    ->group(function (): void {
        Route::get('statuses', [ArticleController::class, 'statuses'])
            ->middleware('can:'.Permission::ViewKnowledge->value)
            ->name('statuses');

        Route::get('categories', [CategoryController::class, 'index'])
            ->middleware('can:'.Permission::ViewKnowledge->value)
            ->name('categories.index');

        Route::post('categories', [CategoryController::class, 'store'])
            ->middleware('can:'.Permission::UpdateKnowledge->value)
            ->name('categories.store');

        Route::put('categories/{category}', [CategoryController::class, 'update'])
            ->middleware('can:'.Permission::UpdateKnowledge->value)
            ->name('categories.update');

        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
            ->middleware('can:'.Permission::DeleteKnowledge->value)
            ->name('categories.destroy');

        // Per-article authorisation lives in the policy: it also has to hide
        // drafts, which a route-level permission check cannot express.
        Route::get('articles', [ArticleController::class, 'index'])
            ->middleware('can:'.Permission::ViewKnowledge->value)
            ->name('articles.index');

        Route::post('articles', [ArticleController::class, 'store'])
            ->middleware('can:'.Permission::CreateKnowledge->value)
            ->name('articles.store');

        Route::get('articles/{article}', [ArticleController::class, 'show'])
            ->middleware('can:'.Permission::ViewKnowledge->value)
            ->name('articles.show');

        Route::put('articles/{article}', [ArticleController::class, 'update'])
            ->name('articles.update');

        Route::delete('articles/{article}', [ArticleController::class, 'destroy'])
            ->name('articles.destroy');
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
