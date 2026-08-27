<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\RegulationAccess;

/**
 * Кто читает регламент и кто его ведёт.
 *
 * Права взяты у курсов (решение пользователя 2026-08-27): регламент — часть той
 * же учебной площадки, и тот, кто ведёт материалы, ведёт и правила. Своего
 * права у регламентов нет.
 *
 * Доступ и право складываются, как и у курсов: доступ решает, существует ли
 * регламент для этого человека, право — можно ли его править. Пропуск
 * администратора через Gate::before для регламентов снят (см.
 * AppServiceProvider) — иначе закрытый регламент не был бы закрытым.
 */
class RegulationPolicy
{
    public function view(User $user, Regulation $regulation): bool
    {
        if (! RegulationAccess::of($user)->allows($regulation)) {
            return false;
        }

        // Черновик виден только тому, кто мог бы его править: пока правило не
        // опубликовано, его ещё пишут, и прочитанное может смениться.
        if ($regulation->isPublished()) {
            return $user->can(Permission::ViewCourses->value);
        }

        return $user->can(Permission::UpdateCourses->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CreateCourses->value);
    }

    public function update(User $user, Regulation $regulation): bool
    {
        return RegulationAccess::of($user)->allows($regulation)
            && $user->can(Permission::UpdateCourses->value);
    }

    public function delete(User $user, Regulation $regulation): bool
    {
        return RegulationAccess::of($user)->allows($regulation)
            && $user->can(Permission::DeleteCourses->value);
    }

    /**
     * Кого пускать в закрытый регламент — и закрывать ли его вообще.
     *
     * Решает тот, кто регламент завёл: закрытость заводят под свой круг людей,
     * и расширять его за автора не вправе даже другой редактор, которого в
     * этот круг впустили. Суперадминистратор — исключение, как и везде.
     */
    public function manageAccess(User $user, Regulation $regulation): bool
    {
        return $regulation->author_id === $user->getKey()
            || RegulationAccess::of($user)->seesEverything();
    }

    /**
     * Отметиться можно только на том, что вправе прочитать.
     */
    public function acknowledge(User $user, Regulation $regulation): bool
    {
        return $regulation->isPublished() && $this->view($user, $regulation);
    }
}
