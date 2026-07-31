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
        $this->ensureQuizWasPassed($enrollment, $lesson);

        return DB::transaction(function () use ($enrollment, $lesson): Enrollment {
            LessonCompletion::firstOrCreate(
                ['enrollment_id' => $enrollment->getKey(), 'lesson_id' => $lesson->getKey()],
                ['completed_at' => now()],
            );

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
            throw new ConflictException('Урок содержит тест — сначала нужно его сдать.');
        }
    }
}
