<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\Role;
use App\Exceptions\ConflictException;
use App\Models\User;

final readonly class SyncUserRoles
{
    /**
     * Replaces a user's roles.
     *
     * @param  list<string>  $roles
     *
     * @throws ConflictException
     */
    public function handle(User $user, array $roles, User $actor): User
    {
        $this->ensureActorKeepsAdminAccess($user, $roles, $actor);
        $this->ensureAnotherAdministratorRemains($user, $roles);

        $user->syncRoles($roles);

        return $user->load('roles.permissions', 'permissions');
    }

    /**
     * Stops an administrator from demoting themselves and losing the ability to
     * undo it in the next request.
     *
     * @param  list<string>  $roles
     *
     * @throws ConflictException
     */
    private function ensureActorKeepsAdminAccess(User $user, array $roles, User $actor): void
    {
        if (! $user->is($actor)) {
            return;
        }

        if (! in_array(Role::Admin->value, $roles, strict: true)) {
            throw new ConflictException('Нельзя снять с себя роль администратора.');
        }
    }

    /**
     * Keeps at least one administrator in the system.
     *
     * @param  list<string>  $roles
     *
     * @throws ConflictException
     */
    private function ensureAnotherAdministratorRemains(User $user, array $roles): void
    {
        $isLosingAdmin = $user->hasRole(Role::Admin->value)
            && ! in_array(Role::Admin->value, $roles, strict: true);

        if (! $isLosingAdmin) {
            return;
        }

        $otherAdministrators = User::query()
            ->role(Role::Admin->value)
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $otherAdministrators) {
            throw new ConflictException('В системе должен остаться хотя бы один администратор.');
        }
    }
}
