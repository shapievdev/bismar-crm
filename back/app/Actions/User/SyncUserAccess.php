<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\AccessLevel;
use App\Exceptions\ConflictException;
use App\Models\User;

final readonly class SyncUserAccess
{
    /**
     * Sets what a person may do: their standing, and their permissions.
     *
     * The two travel together because they are one decision — raising someone
     * to administrator makes their ticked permissions moot, and lowering them
     * back makes the ticks the only thing they have.
     *
     * Every rule here lives in the action rather than in a policy: the people
     * they restrain are exactly the ones Gate::before waves through, so a
     * policy would never be consulted.
     *
     * @param  list<string>  $permissions
     *
     * @throws ConflictException
     */
    public function handle(User $user, AccessLevel $level, array $permissions, User $actor): User
    {
        $current = $user->accessLevel();

        $this->ensureOnlySuperAdminChangesStanding($current, $level, $actor);
        $this->ensureActorKeepsTheirOwn($user, $level, $actor);
        $this->ensureASuperAdminRemains($user, $current, $level);

        $user->syncRoles($level === AccessLevel::User ? [] : [$level->value]);

        // An administrator carries everything by standing, so individual grants
        // would be dead rows that reappear if they are ever demoted.
        $user->syncPermissions($level->grantsEverything() ? [] : $permissions);

        return $user->load('roles', 'permissions');
    }

    /**
     * Administrator and superadmin are appointed by a superadmin alone.
     *
     * @throws ConflictException
     */
    private function ensureOnlySuperAdminChangesStanding(
        AccessLevel $current,
        AccessLevel $next,
        User $actor,
    ): void {
        if ($current === $next || $actor->accessLevel() === AccessLevel::SuperAdmin) {
            return;
        }

        throw new ConflictException('Назначать и снимать администраторов может только суперадминистратор.');
    }

    /**
     * Stops someone from demoting themselves and losing the ability to undo it
     * in the very next request.
     *
     * @throws ConflictException
     */
    private function ensureActorKeepsTheirOwn(User $user, AccessLevel $next, User $actor): void
    {
        if (! $user->is($actor)) {
            return;
        }

        if ($user->accessLevel() !== $next) {
            throw new ConflictException('Нельзя понизить самого себя.');
        }
    }

    /**
     * Keeps at least one superadmin: without one, nobody could ever appoint an
     * administrator again.
     *
     * @throws ConflictException
     */
    private function ensureASuperAdminRemains(User $user, AccessLevel $current, AccessLevel $next): void
    {
        if ($current !== AccessLevel::SuperAdmin || $next === AccessLevel::SuperAdmin) {
            return;
        }

        $others = User::query()
            ->role(AccessLevel::SuperAdmin->value)
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $others) {
            throw new ConflictException('В системе должен остаться хотя бы один суперадминистратор.');
        }
    }
}
