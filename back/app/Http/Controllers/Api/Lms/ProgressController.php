<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\ProgressReport;
use Illuminate\Http\JsonResponse;

/**
 * Кто и как проходит материал — внизу его же страницы.
 *
 * Смотрит администратор и суперадминистратор, и только они (решение
 * пользователя 2026-09-12). Правом это не выразить: `can:` спрашивает Gate, а
 * Gate::before пропускает администраторов и не умеет сказать «и никого больше»,
 * — поэтому маршруты закрыты EnsureAdministrator, см. routes/api.php.
 *
 * Отдельно от `/analytics/learning` намеренно: тот отчёт отвечает «сколько
 * всего и в среднем по компании» и живёт своим экраном под своим правом, а
 * здесь речь об одном материале, открытом прямо сейчас.
 */
final class ProgressController extends Controller
{
    /** Курс: кто его проходит и как далеко ушёл. */
    public function course(Course $course, ProgressReport $report): JsonResponse
    {
        return response()->json(['data' => $report->onCourse($course)]);
    }

    /**
     * Один человек по урокам курса — то, что раскрывается у строки.
     *
     * Своим адресом: программа, помноженная на штат, в общий ответ не
     * помещается, а раскрывают за раз одну строку.
     */
    public function learner(Course $course, User $learner, ProgressReport $report): JsonResponse
    {
        return response()->json(['data' => $report->ofLearner($course, $learner)]);
    }

    /** Один урок: кто его закрыл и чем кончился тест. */
    public function lesson(Lesson $lesson, ProgressReport $report): JsonResponse
    {
        return response()->json(['data' => $report->onLesson($lesson)]);
    }

    /** Документ или справочник: кто с ним ознакомился. */
    public function material(Regulation $regulation, ProgressReport $report): JsonResponse
    {
        return response()->json(['data' => $report->onRegulation($regulation)]);
    }
}
