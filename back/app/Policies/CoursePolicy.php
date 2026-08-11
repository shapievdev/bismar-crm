<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Course;
use App\Models\User;
use App\Support\Lms\CourseAccess;

class CoursePolicy
{
    /**
     * Learners reach published courses; unpublished ones are visible only to
     * those who could edit them.
     *
     * Приватный курс до этого разбора не доходит вовсе: тому, кого в него не
     * пускали, он не существует ни опубликованным, ни черновиком.
     */
    public function view(User $user, Course $course): bool
    {
        if (! CourseAccess::of($user)->allows($course)) {
            return false;
        }

        if ($course->status->isOpenToLearners()) {
            return $user->can(Permission::ViewCourses->value);
        }

        return $user->can(Permission::UpdateCourses->value);
    }

    public function update(User $user, Course $course): bool
    {
        return CourseAccess::of($user)->allows($course)
            && $user->can(Permission::UpdateCourses->value);
    }

    public function delete(User $user, Course $course): bool
    {
        return CourseAccess::of($user)->allows($course)
            && $user->can(Permission::DeleteCourses->value);
    }

    /**
     * Кого пускать в приватный курс — и закрывать ли его вообще.
     *
     * Решает тот, кто курс завёл: приватность заводят под свой круг людей, и
     * расширять его за автора не вправе даже другой редактор, которого в этот
     * круг впустили. Суперадминистратор — исключение, как и везде.
     */
    public function manageAccess(User $user, Course $course): bool
    {
        return $course->author_id === $user->getKey()
            || CourseAccess::of($user)->seesEverything();
    }
}
