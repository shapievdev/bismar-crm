<?php

declare(strict_types=1);

namespace App\Actions\Role;

use App\Enums\Role as RoleEnum;
use App\Exceptions\ConflictException;
use App\Models\Role;

final readonly class DeleteRole
{
    /**
     * These guards live here rather than in a policy: administrators bypass
     * policies via Gate::before, and neither rule may be bypassed.
     *
     * @throws ConflictException
     */
    public function handle(Role $role): void
    {
        if (RoleEnum::tryFrom($role->name) !== null) {
            throw new ConflictException('Встроенную роль удалить нельзя.');
        }

        if ($role->users()->exists()) {
            throw new ConflictException('Нельзя удалить роль, назначенную пользователям.');
        }

        $role->delete();
    }
}
