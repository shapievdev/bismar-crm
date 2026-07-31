<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Enrollment;

final readonly class ProgressCalculator
{
    /**
     * Percentage of the course's lessons the learner has completed.
     *
     * A course with no lessons yet reports 0 rather than 100: an empty course
     * has not been learned, and dividing by zero would claim otherwise.
     */
    public function percentage(Enrollment $enrollment): int
    {
        $total = $this->totalLessons($enrollment);

        if ($total === 0) {
            return 0;
        }

        return (int) round($this->completedLessons($enrollment) / $total * 100);
    }

    public function totalLessons(Enrollment $enrollment): int
    {
        return $enrollment->course->lessons()->count();
    }

    /**
     * Completions are counted against the lessons the course currently has, so
     * removing a lesson cannot leave a learner above 100%.
     */
    public function completedLessons(Enrollment $enrollment): int
    {
        return $enrollment->completions()
            ->whereIn('lesson_id', $enrollment->course->lessons()->select('lessons.id'))
            ->count();
    }

    public function hasFinished(Enrollment $enrollment): bool
    {
        $total = $this->totalLessons($enrollment);

        return $total > 0 && $this->completedLessons($enrollment) >= $total;
    }
}
