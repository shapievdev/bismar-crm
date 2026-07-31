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
     * @throws ConflictException
     */
    public function handle(Course $course, User $learner): Enrollment
    {
        if (! $course->status->isOpenToLearners()) {
            throw new ConflictException('На неопубликованный курс записаться нельзя.');
        }

        return Enrollment::firstOrCreate(
            ['course_id' => $course->getKey(), 'user_id' => $learner->getKey()],
            ['enrolled_at' => now()],
        );
    }
}
