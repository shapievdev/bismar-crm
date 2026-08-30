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

        $this->ensureStandingIsChangedBySomeoneWhoMay($current, $level, $actor);
        $this->ensureActorKeepsTheirOwn($user, $level, $actor);
        $this->ensureASuperAdminRemains($user, $current, $level);

        $user->syncRoles($level === AccessLevel::User ? [] : [$level->value]);

        // An administrator carries everything by standing, so individual grants
        // would be dead rows that reappear if they are ever demoted.
        $user->syncPermissions($level->grantsEverything() ? [] : $permissions);

        return $user->load('roles', 'permissions');
    }

    /**
     * Who may move someone between standings.
     *
     * An administrator appoints and removes administrators; superadmin standing
     * is a superadmin's alone to grant, and an administrator cannot take it
     * away either. Below administrator nobody moves anyone: the right to edit
     * access lets an ordinary person tick permissions, and if it also let them
     * appoint an administrator, a colleague could be handed everything by
     * someone who does not hold it themselves.
     *
     * @throws ConflictException
     */
    private function ensureStandingIsChangedBySomeoneWhoMay(
        AccessLevel $current,
        AccessLevel $next,
        User $actor,
    ): void {
        if ($current === $next) {
            return;
        }

        $actorLevel = $actor->accessLevel();

        if ($actorLevel === AccessLevel::SuperAdmin) {
            return;
        }

        if ($actorLevel !== AccessLevel::Admin) {
            throw new ConflictException('Менять уровень доступа может только администратор.');
        }

        if ($current === AccessLevel::SuperAdmin || $next === AccessLevel::SuperAdmin) {
            throw new ConflictException('Назначать и снимать суперадминистраторов может только суперадминистратор.');
        }
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
     * Keeps at least one superadmin: without one, nobody could ever appoint a
     * superadmin again, and what only they may reach — the consultant's
     * settings among it — would be shut for good.
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
