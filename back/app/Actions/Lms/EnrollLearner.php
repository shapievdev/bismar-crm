<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Exceptions\ConflictException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

final readonly class EnrollLearner
{
    /**
     * Enrols a learner, or returns the enrolment they already have.
     *
     * Idempotent by design: a double-clicked button must not raise an error or
     * reset the learner's progress.
     *
     * `$deliberate` — взялся человек за курс или просто открыл урок.
     * Запись заводится в обоих случаях, чтобы прогресс считался сам, а вот в
     * «мои материалы» попадает только начатое: иначе список превращается в
     * историю просмотров, где начатое уже не найти.
     *
     * @throws ConflictException
     */
    public function handle(Course $course, User $learner, bool $deliberate = true): Enrollment
    {
        if (! $course->status->isOpenToLearners()) {
            throw new ConflictException('На неопубликованный курс записаться нельзя.');
        }

        $enrollment = Enrollment::firstOrCreate(
            ['course_id' => $course->getKey(), 'user_id' => $learner->getKey()],
            ['enrolled_at' => now()],
        );

        // Отметка ставится один раз: нажатие кнопки на курсе, к которому уже
        // приступили, не должно двигать дату начала.
        if ($deliberate && $enrollment->started_at === null) {
            $enrollment->forceFill(['started_at' => now()])->save();
        }

        return $enrollment;
    }
}
