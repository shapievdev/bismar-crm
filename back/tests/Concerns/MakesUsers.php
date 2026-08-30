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

    /** Reads the knowledge base and nothing else. */
    protected function learner(): User
    {
        return $this->userWith(Permission::ViewCourses);
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
