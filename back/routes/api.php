<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\Api\Ai\ConsultantController;
use App\Http\Controllers\Api\Ai\QuestionLogController;
use App\Http\Controllers\Api\Ai\SettingsController;
use App\Http\Controllers\Api\Analytics\CustomerController as AnalyticsCustomerController;
use App\Http\Controllers\Api\Analytics\DirectoryController as AnalyticsDirectoryController;
use App\Http\Controllers\Api\Analytics\ProductController as AnalyticsProductController;
use App\Http\Controllers\Api\Analytics\SalesController as AnalyticsSalesController;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\Chat\ContactController;
use App\Http\Controllers\Api\Chat\ConversationController;
use App\Http\Controllers\Api\Chat\MessageController;
use App\Http\Controllers\Api\Chat\ParticipantController;
use App\Http\Controllers\Api\Lms\CategoryController;
use App\Http\Controllers\Api\Lms\CourseAccessController;
use App\Http\Controllers\Api\Lms\CourseController;
use App\Http\Controllers\Api\Lms\CourseExpertController;
use App\Http\Controllers\Api\Lms\CourseStructureController;
use App\Http\Controllers\Api\Lms\LearningController;
use App\Http\Controllers\Api\Lms\LessonAnswerController;
use App\Http\Controllers\Api\Lms\LessonAttachmentController;
use App\Http\Controllers\Api\Lms\LessonTranscriptController;
use App\Http\Controllers\Api\Lms\QuizController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureCourseAccess;
use App\Support\Analytics\ProductReport;
use App\Support\Analytics\SalesReport;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->as('auth.')->group(function (): void {
    Route::post('register', [RegisteredUserController::class, 'store'])->name('register');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('user', [AuthenticatedUserController::class, 'show'])->name('user');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

// EnsureCourseAccess закрывает всю группу разом: право на маршруте говорит,
// что человек умеет делать, но не с каким курсом, — а приватный курс закрыт от
// всех, кого в него не пускали, вплоть до администратора.
Route::middleware(['auth:sanctum', EnsureCourseAccess::class])->prefix('lms')->as('lms.')->group(function (): void {
    $view = 'can:'.Permission::ViewCourses->value;
    $create = 'can:'.Permission::CreateCourses->value;
    $update = 'can:'.Permission::UpdateCourses->value;
    $delete = 'can:'.Permission::DeleteCourses->value;

    // The consultant quotes published material only, so reading the base and
    // asking about it answer to the same right.
    Route::post('ask', [ConsultantController::class, 'ask'])->middleware($view)->name('ask');

    // Своя переписка, и только своя: отбор идёт по спрашивавшему, а не по
    // тому, что попросил клиент.
    Route::get('ask/history', [ConsultantController::class, 'history'])->middleware($view)->name('ask.history');
    Route::delete('ask/history', [ConsultantController::class, 'forget'])->middleware($view)->name('ask.forget');

    // Что сотрудник думает о полученном ответе и просьба дописать его.
    // Ставится только на свой вопрос — проверяет контроллер.
    Route::post('ask/{question}/feedback', [ConsultantController::class, 'feedback'])
        ->middleware($view)
        ->name('ask.feedback');
    Route::post('ask/{question}/request', [ConsultantController::class, 'requestFollowUp'])
        ->middleware($view)
        ->name('ask.request');

    Route::get('statuses', [CourseController::class, 'statuses'])->middleware($view)->name('statuses');
    Route::get('categories', [CategoryController::class, 'index'])->middleware($view)->name('categories.index');

    // Learning. Per-course visibility is decided by the policy, which also has
    // to hide unpublished courses — a route-level check cannot express that.
    Route::get('my-courses', [LearningController::class, 'myEnrollments'])->middleware($view)->name('my-courses');
    Route::post('courses/{course}/enroll', [LearningController::class, 'enroll'])->middleware($view)->name('enroll');
    Route::get('lessons/{lesson}', [LearningController::class, 'showLesson'])->middleware($view)->name('lessons.show');
    Route::post('lessons/{lesson}/complete', [LearningController::class, 'completeLesson'])->middleware($view)->name('lessons.complete');
    Route::post('lessons/{lesson}/quiz/submit', [LearningController::class, 'submitQuiz'])->middleware($view)->name('quiz.submit');

    // Разбор своей попытки. Чужая недоступна — проверяется в контроллере, по
    // спрашивавшему, а не по тому, что попросил клиент.
    Route::get('quiz-attempts/{attempt}', [LearningController::class, 'showAttempt'])
        ->middleware($view)
        ->name('attempts.show');

    // Catalogue.
    Route::get('courses', [CourseController::class, 'index'])->middleware($view)->name('courses.index');
    Route::get('courses/{course}', [CourseController::class, 'show'])->middleware($view)->name('courses.show');

    // Кого пускать в приватный курс. Права на курсы здесь ни при чём: список
    // ведёт автор, а он не обязан уметь ничего сверх того, чем уже завёл курс,
    // — см. CoursePolicy::manageAccess, которой эти маршруты и закрыты.
    Route::get('courses/{course}/access', [CourseAccessController::class, 'show'])->name('courses.access.show');
    Route::put('courses/{course}/access', [CourseAccessController::class, 'update'])->name('courses.access.update');
    Route::get('courses/{course}/access/candidates', [CourseAccessController::class, 'candidates'])
        ->name('courses.access.candidates');

    // Кто отвечает за курс. В отличие от доступа — право редакторское, а не
    // авторское: назначить ответственного значит сказать, к кому идти с
    // вопросом, а не открыть кому-то закрытое.
    Route::middleware($update)->group(function (): void {
        Route::get('courses/{course}/experts', [CourseExpertController::class, 'show'])
            ->name('courses.experts.show');
        Route::put('courses/{course}/experts', [CourseExpertController::class, 'update'])
            ->name('courses.experts.update');
        Route::get('courses/{course}/experts/candidates', [CourseExpertController::class, 'candidates'])
            ->name('courses.experts.candidates');
    });

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

        // Разбор теста для автора: где урок не научил. Право то же, что на
        // правку урока, — чинить дыру всё равно правкой материала.
        Route::get('lessons/{lesson}/quiz/statistics', [QuizController::class, 'statistics'])->name('quiz.statistics');

        // Таблица «вопрос — ответ — источник». Право то же, что на правку
        // урока: это часть материала, а не отдельная сущность.
        Route::put('lessons/{lesson}/answers', [LessonAnswerController::class, 'save'])->name('answers.save');
        Route::post('lessons/{lesson}/answers/suggest', [LessonAnswerController::class, 'suggest'])->name('answers.suggest');

        // Расшифровки. Тоже под правом на правку, и это единственный способ до
        // них добраться: читателю они не видны — он читает материал, а не его
        // изложение для машины.
        Route::get('lessons/{lesson}/transcripts', [LessonTranscriptController::class, 'index'])->name('transcripts.index');
        Route::post('lessons/{lesson}/transcripts', [LessonTranscriptController::class, 'store'])->name('transcripts.store');
        Route::delete('transcripts/{transcript}', [LessonTranscriptController::class, 'destroy'])->name('transcripts.destroy');
    });

    Route::middleware($delete)->group(function (): void {
        Route::delete('modules/{module}', [CourseStructureController::class, 'destroyModule'])->name('modules.destroy');
        Route::delete('lessons/{lesson}', [CourseStructureController::class, 'destroyLesson'])->name('lessons.destroy');
    });
});

Route::middleware('auth:sanctum')->prefix('profile')->as('profile.')->group(function (): void {
    // Your own account: guarded by being signed in, nothing more.
    Route::put('/', [ProfileController::class, 'update'])->name('update');
    // Knowing the current password is what stands in for a permission here.
    Route::put('password', [ProfileController::class, 'updatePassword'])->name('password.update');
    Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
    Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
});

Route::middleware('auth:sanctum')->prefix('ai')->as('ai.')->group(function (): void {
    // Кто может их менять, решает не маршрут: администраторы проходят
    // Gate::before, поэтому уровень проверяется в контроллере и действии.
    // Журнал — авторам материала: пробел в базе закрывают они.
    Route::middleware('can:'.Permission::UpdateCourses->value)->group(function (): void {
        Route::get('questions', [QuestionLogController::class, 'index'])->name('questions.index');

        // Ответ на заданный вопрос: строкой в урок и обратно в тот разговор,
        // где вопрос был задан.
        Route::post('questions/{question}/answer', [QuestionLogController::class, 'resolve'])
            ->name('questions.resolve');

        // Вопросы, которым в перечне пробелов не место: случайные нажатия и
        // проверки «а что ты умеешь».
        Route::delete('questions/{question}', [QuestionLogController::class, 'destroy'])
            ->name('questions.destroy');
    });

    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/test', [SettingsController::class, 'test'])->name('settings.test');
});

// Торговая аналитика. Читает ClickHouse, ничего не пишет, и потому вся группа
// закрыта одним правом на просмотр: разделять «кто видит выручку» и «кто видит
// её по менеджерам» нечем — это одна и та же цифра в двух разрезах.
Route::middleware(['auth:sanctum', 'can:'.Permission::ViewAnalytics->value])
    ->prefix('analytics')
    ->as('analytics.')
    ->group(function (): void {
        // Списки для фильтров и дата, по которую доехала витрина.
        Route::get('directory', [AnalyticsDirectoryController::class, 'index'])->name('directory');

        Route::get('sales', [AnalyticsSalesController::class, 'index'])->name('sales');
        // Разрез ограничен на маршруте: имя колонки попадает в SQL склейкой,
        // и неизвестное значение должно упереться в 404, а не дойти до отчёта.
        Route::get('sales/breakdown/{dimension}', [AnalyticsSalesController::class, 'breakdown'])
            ->whereIn('dimension', SalesReport::dimensions())
            ->name('sales.breakdown');

        Route::get('customers', [AnalyticsCustomerController::class, 'index'])->name('customers');

        Route::get('products', [AnalyticsProductController::class, 'index'])->name('products');
        Route::get('products/breakdown/{dimension}', [AnalyticsProductController::class, 'breakdown'])
            ->whereIn('dimension', ProductReport::dimensions())
            ->name('products.breakdown');
    });

Route::middleware('auth:sanctum')->group(function (): void {
    // The catalogue the access editor ticks through, so it answers to the same
    // right as editing a person's access does.
    Route::get('permissions', [PermissionController::class, 'index'])
        ->middleware('can:'.Permission::ManageUsers->value)
        ->name('permissions.index');

    Route::get('users', [UserController::class, 'index'])
        ->middleware('can:'.Permission::ViewUsers->value)
        ->name('users.index');

    Route::middleware('can:'.Permission::ManageUsers->value)->group(function (): void {
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');

        // Separate from the record itself: who may grant what is its own
        // question, answered in SyncUserAccess.
        Route::put('users/{user}/access', [UserController::class, 'updateAccess'])->name('users.access.update');
    });
});

/*
 * Мессенджер сотрудников.
 *
 * Открыт всякому, кто вошёл: написать коллеге — не то же, что читать базу
 * знаний, и права здесь ни при чём. Закрыта только чужая переписка, и закрыта
 * наглухо — политикой, а не маршрутом.
 */
Route::middleware('auth:sanctum')->prefix('chat')->as('chat.')->group(function (): void {
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::put('conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');

    // Удаление: «у себя» убирает переписку из своего списка, «у всех» стирает
    // разговор целиком. Что именно — говорит `scope` в теле запроса.
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])
        ->name('conversations.destroy');

    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])
        ->name('conversations.read');
    Route::post('conversations/{conversation}/leave', [ConversationController::class, 'leave'])
        ->name('conversations.leave');

    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])
        ->name('messages.index');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->name('messages.store');
    Route::patch('conversations/{conversation}/messages/{message}', [MessageController::class, 'update'])
        ->name('messages.update');
    Route::delete('conversations/{conversation}/messages/{message}', [MessageController::class, 'destroy'])
        ->name('messages.destroy');

    Route::post('conversations/{conversation}/participants', [ParticipantController::class, 'store'])
        ->name('participants.store');
    Route::delete('conversations/{conversation}/participants/{user}', [ParticipantController::class, 'destroy'])
        ->name('participants.destroy');

    // Счётчик для навигации: спрашивается один раз при входе, дальше его
    // поддерживает сокет.
    Route::get('unread', [ConversationController::class, 'unreadTotal'])->name('unread');

    Route::get('contacts', [ContactController::class, 'index'])->name('contacts');
});
