<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\User;

/**
 * People to act as, described by what they may do rather than by a job title —
 * which is how the application itself now works.
 */
trait MakesUsers
{
    protected function userWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $user->givePermissionTo(array_map(
                static fn (Permission $permission): string => $permission->value,
                $permissions,
            ));
        }

        return $user;
    }

    /**
     * Reads the knowledge base and nothing else.
     *
     * Все три раздела: разделы базы знаний получили свои права (2026-09-11), но
     * «читатель базы» по-прежнему означает человека, которому она открыта
     * целиком. Кому открыт один раздел из трёх — отдельный случай, и заводят
     * его там, где он и проверяется, через userWith().
     */
    protected function learner(): User
    {
        return $this->userWith(
            Permission::ViewCourses,
            Permission::ViewDocuments,
            Permission::ViewHandbooks,
        );
    }

    /**
     * Правит материалы, но не выпускает их к людям: публикация — отдельное
     * право, и без него человек собирает материал, а показывает его компании
     * кто-то другой.
     */
    protected function editor(): User
    {
        return $this->userWith(
            Permission::ViewCourses,
            Permission::CreateCourses,
            Permission::UpdateCourses,
            Permission::ViewDocuments,
            Permission::CreateDocuments,
            Permission::UpdateDocuments,
            Permission::ViewHandbooks,
            Permission::CreateHandbooks,
            Permission::UpdateHandbooks,
        );
    }

    /** Writes the knowledge base. */
    protected function author(): User
    {
        return $this->userWith(
            Permission::ViewCourses,
            Permission::CreateCourses,
            Permission::UpdateCourses,
            Permission::DeleteCourses,
            Permission::PublishCourses,
            Permission::ViewDocuments,
            Permission::CreateDocuments,
            Permission::UpdateDocuments,
            Permission::DeleteDocuments,
            Permission::ViewHandbooks,
            Permission::CreateHandbooks,
            Permission::UpdateHandbooks,
            Permission::DeleteHandbooks,
        );
    }

    protected function administrator(): User
    {
        return User::factory()->create()->assignRole(AccessLevel::Admin->value);
    }

    protected function superAdministrator(): User
    {
        return User::factory()->create()->assignRole(AccessLevel::SuperAdmin->value);
    }
}
