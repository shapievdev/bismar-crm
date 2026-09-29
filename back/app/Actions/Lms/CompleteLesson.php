<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Exceptions\ConflictException;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\MaterialVersion;
use App\Support\Lms\MaterialDues;
use App\Support\Lms\MaterialVersions;
use App\Support\Lms\ProgressCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class CompleteLesson
{
    public function __construct(
        private ProgressCalculator $progress,
        private MaterialDues $dues,
        private MaterialVersions $versions,
    ) {}

    /**
     * Marks a lesson done for this enrolment and closes the course if that was
     * the last one.
     *
     * Отметка одна на человека, а версия — пометка на ней (2026-09-25): версия
     * у него одна, и требовать пройти все значило бы требовать выучить чужие
     * правила. Какую именно он прошёл, спрашивается здесь же, если не сказали:
     * сдать могли и с общей страницы урока.
     *
     * @throws ConflictException
     */
    public function handle(Enrollment $enrollment, Lesson $lesson, ?MaterialVersion $version = null): Enrollment
    {
        $version ??= $this->versionFor($enrollment, $lesson);

        $this->ensureLessonBelongsToCourse($enrollment, $lesson);
        $this->ensureEarlierLessonsAreDone($enrollment, $lesson);

        // Требования спрашиваются у того текста, который человеку и
        // предназначен: свой тест и свой опрос у версии, общие — у урока.
        $required = $version ?? $lesson;

        $this->ensureQuizWasPassed($enrollment, $required);
        $this->ensureSurveyWasAnswered($enrollment, $required);

        return DB::transaction(function () use ($enrollment, $lesson, $version): Enrollment {
            LessonCompletion::firstOrCreate(
                ['enrollment_id' => $enrollment->getKey(), 'lesson_id' => $lesson->getKey()],
                ['completed_at' => now(), 'version_id' => $version?->getKey()],
            );

            // Пройденный урок — это «взялся за курс», даже если кнопку начала
            // никто не нажимал. С этого мгновения курс числится в своих.
            if ($enrollment->started_at === null) {
                $enrollment->forceFill(['started_at' => now()])->save();
            }

            return $this->refreshCourseCompletion($enrollment);
        });
    }

    /**
     * Версия урока, которую проходит этот человек. Null — общая, то есть сам
     * урок; так и у большинства уроков, которые на версии не делили вовсе.
     */
    private function versionFor(Enrollment $enrollment, Lesson $lesson): ?MaterialVersion
    {
        $learner = $enrollment->loadMissing('user')->user;

        return $learner === null ? null : $this->versions->defaultFor($lesson, $learner);
    }

    /**
     * Обязательный опрос при уроке держит зачёт так же, как тест (решение
     * пользователя 2026-09-21): «пройдено» нажимают после того, как высказались,
     * а не вместо.
     *
     * Необязательный не держит ничего — его на то и заводят, чтобы спросить тех,
     * кому есть что сказать.
     *
     * @param  Lesson|MaterialVersion  $required  урок или та его версия, которую
     *                                            проходит этот человек
     *
     * @throws ConflictException
     */
    private function ensureSurveyWasAnswered(Enrollment $enrollment, Model $required): void
    {
        $learner = $enrollment->loadMissing('user')->user;

        if ($learner === null || ! $this->dues->surveyPending($required, $learner)) {
            return;
        }

        throw new ConflictException($this->dues->pendingMessage($required));
    }

    /**
     * Recomputes whether the course as a whole is finished. Called after any
     * change to completions, so adding a lesson to a finished course correctly
     * reopens it.
     */
    public function refreshCourseCompletion(Enrollment $enrollment): Enrollment
    {
        $enrollment->load('course');

        $hasFinished = $this->progress->hasFinished($enrollment);

        if ($hasFinished && ! $enrollment->isCompleted()) {
            $enrollment->update(['completed_at' => now()]);
        }

        if (! $hasFinished && $enrollment->isCompleted()) {
            $enrollment->update(['completed_at' => null]);
        }

        return $enrollment->refresh();
    }

    /**
     * Урок, из-за которого этот пока нельзя закрыть, — первый непройденный из
     * стоящих раньше. Null означает «путь открыт».
     *
     * Курс проходят по порядку: перескочив середину, человек досдаёт последний
     * урок и получает курс «пройденным», не открыв половины (решение
     * пользователя 2026-08-31). Порядок — тот же, в каком уроки читают:
     * по модулям, внутри модуля по номеру шага.
     *
     * Уже отмеченное прежде не отзывается: правило про новые отметки, а не про
     * прошлые, — иначе добавленный в начало курса урок разом обнулил бы
     * пройденное у всех.
     */
    public function blockedBy(Enrollment $enrollment, Lesson $lesson): ?Lesson
    {
        $enrollment->loadMissing('course');

        $lessons = $enrollment->course->lessons()->get(['lessons.id', 'lessons.title']);
        $index = $lessons->search(fn (Lesson $candidate): bool => $candidate->is($lesson));

        // Первый урок никому не подчинён; неизвестный курсу не наше дело —
        // о нём скажет ensureLessonBelongsToCourse.
        if ($index === false || $index === 0) {
            return null;
        }

        $done = $enrollment->completions()->pluck('lesson_id')->map(intval(...))->all();

        return $lessons->take($index)->first(
            fn (Lesson $earlier): bool => ! in_array((int) $earlier->getKey(), $done, strict: true),
        );
    }

    /**
     * @throws ConflictException
     */
    private function ensureEarlierLessonsAreDone(Enrollment $enrollment, Lesson $lesson): void
    {
        $blocker = $this->blockedBy($enrollment, $lesson);

        if ($blocker !== null) {
            throw new ConflictException(sprintf(
                'Сначала пройдите предыдущие уроки — начните с «%s».',
                $blocker->title,
            ));
        }
    }

    /**
     * @throws ConflictException
     */
    private function ensureLessonBelongsToCourse(Enrollment $enrollment, Lesson $lesson): void
    {
        $belongs = $enrollment->course->lessons()->whereKey($lesson->getKey())->exists();

        if (! $belongs) {
            throw new ConflictException('Урок не относится к этому курсу.');
        }
    }

    /**
     * A lesson carrying a quiz is completed by passing it, never by simply
     * clicking "done" — otherwise the test could be skipped entirely.
     *
     * Сдать — значит ответить верно на все вопросы: планка теста при уроке
     * равна ста процентам, см. Quiz::PASSING_SCORE.
     *
     * Спрашивается тест того текста, который человеку предназначен: у версии он
     * свой, и требовать сдачи общего значило бы спросить с розницы по бланку
     * офиса.
     *
     * @param  Lesson|MaterialVersion  $required
     *
     * @throws ConflictException
     */
    private function ensureQuizWasPassed(Enrollment $enrollment, Model $required): void
    {
        $required->loadMissing('quiz');

        if ($required->quiz === null) {
            return;
        }

        $passed = $required->quiz->attempts()
            ->where('user_id', $enrollment->user_id)
            ->where('passed', true)
            ->exists();

        if (! $passed) {
            throw new ConflictException(
                'Урок содержит тест — он зачтётся, когда все ответы будут верными.',
            );
        }
    }
}
