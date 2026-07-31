<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /**
     * Learners reach published courses; unpublished ones are visible only to
     * those who could edit them.
     */
    public function view(User $user, Course $course): bool
    {
        if ($course->status->isOpenToLearners()) {
            return $user->can(Permission::ViewCourses->value);
        }

        return $user->can(Permission::UpdateCourses->value);
    }

    public function update(User $user, Course $course): bool
    {
        return $user->can(Permission::UpdateCourses->value);
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->can(Permission::DeleteCourses->value);
    }
}
