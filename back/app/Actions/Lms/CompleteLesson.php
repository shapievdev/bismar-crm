<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Exceptions\ConflictException;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Support\Lms\ProgressCalculator;
use Illuminate\Support\Facades\DB;

final readonly class CompleteLesson
{
    public function __construct(private ProgressCalculator $progress) {}

    /**
     * Marks a lesson done for this enrolment and closes the course if that was
     * the last one.
     *
     * @throws ConflictException
     */
    public function handle(Enrollment $enrollment, Lesson $lesson): Enrollment
    {
        $this->ensureLessonBelongsToCourse($enrollment, $lesson);
        $this->ensureEarlierLessonsAreDone($enrollment, $lesson);
        $this->ensureQuizWasPassed($enrollment, $lesson);

        return DB::transaction(function () use ($enrollment, $lesson): Enrollment {
            LessonCompletion::firstOrCreate(
                ['enrollment_id' => $enrollment->getKey(), 'lesson_id' => $lesson->getKey()],
                ['completed_at' => now()],
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
     * @throws ConflictException
     */
    private function ensureQuizWasPassed(Enrollment $enrollment, Lesson $lesson): void
    {
        $lesson->loadMissing('quiz');

        if ($lesson->quiz === null) {
            return;
        }

        $passed = $lesson->quiz->attempts()
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
