<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\Lms\CategoryController;
use App\Http\Controllers\Api\Lms\CourseController;
use App\Http\Controllers\Api\Lms\CourseStructureController;
use App\Http\Controllers\Api\Lms\LearningController;
use App\Http\Controllers\Api\Lms\LessonAttachmentController;
use App\Http\Controllers\Api\Lms\QuizController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfileController;
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

Route::middleware('auth:sanctum')->prefix('lms')->as('lms.')->group(function (): void {
    $view = 'can:'.Permission::ViewCourses->value;
    $create = 'can:'.Permission::CreateCourses->value;
    $update = 'can:'.Permission::UpdateCourses->value;
    $delete = 'can:'.Permission::DeleteCourses->value;

    Route::get('statuses', [CourseController::class, 'statuses'])->middleware($view)->name('statuses');
    Route::get('categories', [CategoryController::class, 'index'])->middleware($view)->name('categories.index');

    // Learning. Per-course visibility is decided by the policy, which also has
    // to hide unpublished courses — a route-level check cannot express that.
    Route::get('my-courses', [LearningController::class, 'myEnrollments'])->middleware($view)->name('my-courses');
    Route::post('courses/{course}/enroll', [LearningController::class, 'enroll'])->middleware($view)->name('enroll');
    Route::get('lessons/{lesson}', [LearningController::class, 'showLesson'])->middleware($view)->name('lessons.show');
    Route::post('lessons/{lesson}/complete', [LearningController::class, 'completeLesson'])->middleware($view)->name('lessons.complete');
    Route::post('lessons/{lesson}/quiz/submit', [LearningController::class, 'submitQuiz'])->middleware($view)->name('quiz.submit');

    // Catalogue.
    Route::get('courses', [CourseController::class, 'index'])->middleware($view)->name('courses.index');
    Route::get('courses/{course}', [CourseController::class, 'show'])->middleware($view)->name('courses.show');

    // Authoring.
    Route::post('courses', [CourseController::class, 'store'])->middleware($create)->name('courses.store');
    Route::put('courses/{course}', [CourseController::class, 'update'])->middleware($update)->name('courses.update');
    Route::delete('courses/{course}', [CourseController::class, 'destroy'])->middleware($delete)->name('courses.destroy');

    Route::middleware($update)->group(function (): void {
        Route::post('courses/{course}/cover', [CourseController::class, 'storeCover'])->name('courses.cover.store');
        Route::delete('courses/{course}/cover', [CourseController::class, 'destroyCover'])->name('courses.cover.destroy');

        Route::post('courses/{course}/modules', [CourseStructureController::class, 'storeModule'])->name('modules.store');
        Route::put('modules/{module}', [CourseStructureController::class, 'updateModule'])->name('modules.update');
        Route::post('modules/{module}/lessons', [CourseStructureController::class, 'storeLesson'])->name('lessons.store');
        Route::put('lessons/{lesson}', [CourseStructureController::class, 'updateLesson'])->name('lessons.update');

        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::post('lessons/{lesson}/attachments', [LessonAttachmentController::class, 'store'])->name('attachments.store');
        Route::post('lessons/{lesson}/video', [LessonAttachmentController::class, 'storeVideo'])->name('video.store');
        Route::delete('lessons/{lesson}/video', [LessonAttachmentController::class, 'destroyVideo'])->name('video.destroy');
        Route::put('attachments/{attachment}', [LessonAttachmentController::class, 'update'])->name('attachments.update');
        Route::delete('attachments/{attachment}', [LessonAttachmentController::class, 'destroy'])->name('attachments.destroy');

        Route::put('lessons/{lesson}/quiz', [QuizController::class, 'save'])->name('quiz.save');
        Route::delete('lessons/{lesson}/quiz', [QuizController::class, 'destroy'])->name('quiz.destroy');
    });

    Route::middleware($delete)->group(function (): void {
        Route::delete('modules/{module}', [CourseStructureController::class, 'destroyModule'])->name('modules.destroy');
        Route::delete('lessons/{lesson}', [CourseStructureController::class, 'destroyLesson'])->name('lessons.destroy');
    });
});

Route::middleware('auth:sanctum')->prefix('profile')->as('profile.')->group(function (): void {
    // Your own account: guarded by being signed in, nothing more.
    Route::put('/', [ProfileController::class, 'update'])->name('update');
    Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
    Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
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
